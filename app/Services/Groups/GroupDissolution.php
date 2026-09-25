<?php

namespace App\Services\Groups;

use App\Enums\RoundPhase;
use App\Enums\Visibility;
use App\Mail\GroupDissolvedMail;
use App\Models\Activity;
use App\Models\Attachment;
use App\Models\CartItem;
use App\Models\Group;
use App\Models\Manufacturer;
use App\Models\Note;
use App\Models\OrderProposal;
use App\Models\PriceObservation;
use App\Models\Product;
use App\Models\ProposalItem;
use App\Models\Round;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Dissolves a group without breaking the orders of other groups:
 *
 *   - rounds, memberships and invitations go away, and so does everything
 *     only the group could see: private catalog entries, notes, documents
 *     and price history
 *   - shared manufacturers and products stay and belong to the community
 *     afterwards — moderators of any group may maintain them
 *   - private products another group already ordered stay archived, so
 *     those orders keep working
 *   - notes and documents on shared entries stay, without names
 */
class GroupDissolution
{
    /**
     * While money or goods are on their way, a group can't be dissolved.
     *
     * @var array<int, RoundPhase>
     */
    public const BLOCKING_PHASES = [RoundPhase::Payment, RoundPhase::Ordering, RoundPhase::Delivery, RoundPhase::Pickup];

    /**
     * Files of deleted documents. They are removed only after the
     * transaction committed, so a failed dissolution leaves no dead links.
     * Product images take care of themselves (see Product).
     *
     * @var array<int, array{disk: string, path: string}>
     */
    private array $orphanedFiles = [];

    /**
     * @return array<int, string>
     */
    public function blockingReasons(Group $group): array
    {
        $running = $this->rounds()
            ->whereBelongsTo($group)
            ->whereIn('phase', array_map(fn (RoundPhase $phase): string => $phase->value, self::BLOCKING_PHASES))
            ->orderBy('title')
            ->pluck('title');

        if ($running->isEmpty()) {
            return [];
        }

        return ['Hier sind noch Geld oder Ware unterwegs: '.$running->implode(', ').'. Schließt diese Runden erst ab oder brecht sie ab.'];
    }

    /**
     * What dissolving would do — shown before the owner confirms.
     *
     * @return array{rounds: int, members: int, deleted_products: int, kept_products: int, deleted_manufacturers: int, kept_manufacturers: int}
     */
    public function preview(Group $group): array
    {
        $products = $this->ownedProducts($group);
        $deletedProductIds = $products->reject(fn (Product $product): bool => $this->mustKeepProduct($product, $group))->modelKeys();

        $manufacturers = $this->ownedManufacturers($group);
        $deletedManufacturers = $manufacturers->reject(fn (Manufacturer $manufacturer): bool => $this->mustKeepManufacturer($manufacturer, $deletedProductIds))->count();

        return [
            'rounds' => $this->rounds()->whereBelongsTo($group)->count(),
            'members' => $group->members()->count(),
            'deleted_products' => count($deletedProductIds),
            'kept_products' => $products->count() - count($deletedProductIds),
            'deleted_manufacturers' => $deletedManufacturers,
            'kept_manufacturers' => $manufacturers->count() - $deletedManufacturers,
        ];
    }

    /**
     * @return array{notified: int, failed: int}
     */
    public function dissolve(Group $group, User $by, bool $notifyMembers = true): array
    {
        $this->ensure($by->isOwnerOf($group), 'Nur der Owner kann die Gruppe auflösen.');

        $reasons = $this->blockingReasons($group);
        $this->ensure($reasons === [], $reasons[0] ?? '');

        $formerMembers = $group->members()->whereKeyNot($by->getKey())->get();
        $groupName = $group->name;
        $this->orphanedFiles = [];

        DB::transaction(function () use ($group, $groupName): void {
            foreach ($this->rounds()->whereBelongsTo($group)->get() as $round) {
                $this->deleteAnnotations($round);
                $round->delete();
            }

            $deletedProductIds = [];

            foreach ($this->ownedProducts($group) as $product) {
                if ($this->mustKeepProduct($product, $group)) {
                    $this->releaseToCommunity($product, $groupName);

                    continue;
                }

                $deletedProductIds[] = $product->id;
                $this->deleteAnnotations($product);
                $product->forceDelete();
            }

            foreach ($this->ownedManufacturers($group) as $manufacturer) {
                if ($this->mustKeepManufacturer($manufacturer, $deletedProductIds)) {
                    $this->releaseToCommunity($manufacturer, $groupName);

                    continue;
                }

                $this->deleteAnnotations($manufacturer);
                $manufacturer->delete();
            }

            $this->deletePrivateTraces($group);

            $group->delete();
        });

        // Loaded memberships would still list the dissolved group.
        $by->unsetRelation('groups');

        foreach ($this->orphanedFiles as $file) {
            Storage::disk($file['disk'])->delete($file['path']);
        }

        return $notifyMembers
            ? $this->notify($formerMembers, $groupName, $by)
            : ['notified' => 0, 'failed' => 0];
    }

    /**
     * Shared entries stay for everybody. Private ones stay as well when
     * another group already ordered them, so those orders keep working.
     */
    private function mustKeepProduct(Product $product, Group $group): bool
    {
        if ($product->isPublic()) {
            return true;
        }

        $foreignRounds = $this->rounds()->where('group_id', '!=', $group->id)->select('id');

        return CartItem::query()->where('product_id', $product->id)->whereIn('round_id', $foreignRounds)->exists()
            || ProposalItem::query()->where('product_id', $product->id)
                ->whereIn('proposal_id', OrderProposal::query()->whereIn('round_id', $foreignRounds)->select('id'))
                ->exists()
            || $product->priceObservations()->where('group_id', '!=', $group->id)->exists()
            || DB::table('round_product')->where('product_id', $product->id)->whereIn('round_id', $foreignRounds)->exists();
    }

    /**
     * A manufacturer stays while any product still points to it — deleting
     * it would take the products of other groups with it.
     *
     * @param  array<int, int>  $deletedProductIds
     */
    private function mustKeepManufacturer(Manufacturer $manufacturer, array $deletedProductIds): bool
    {
        return $manufacturer->isPublic()
            || $manufacturer->products()->withTrashed()->whereKeyNot($deletedProductIds)->exists();
    }

    /**
     * Nobody owns the entry anymore. A private product is archived on top:
     * it stays for the orders that use it, but nobody maintains its prices.
     */
    private function releaseToCommunity(Product|Manufacturer $entry, string $groupName): void
    {
        $entry->forceFill(['group_id' => null])->saveQuietly();

        if ($entry instanceof Product && ! $entry->isPublic() && ! $entry->trashed()) {
            $entry->deleteQuietly();
        }

        $entry->activities()->create([
            'action' => 'ownership_released',
            'properties' => ['group' => $groupName],
        ]);
    }

    /**
     * Notes, documents and the activity stream hang on their record
     * polymorphically, so the database doesn't delete them on its own.
     */
    private function deleteAnnotations(Round|Product|Manufacturer $record): void
    {
        $record->notes()->delete();
        $this->deleteAttachments($record->attachments()->getQuery());
        $record->activities()->delete();
    }

    /**
     * What the group left on entries only it could see. On shared entries
     * notes, documents, activities and prices stay — without names.
     */
    private function deletePrivateTraces(Group $group): void
    {
        Note::query()
            ->where('group_id', $group->id)
            ->whereNot(fn (Builder $query) => $this->onSharedEntry($query, 'notable'))
            ->delete();

        $this->deleteAttachments(Attachment::query()
            ->where('group_id', $group->id)
            ->whereNot(fn (Builder $query) => $this->onSharedEntry($query, 'attachable')));

        Activity::query()
            ->where('group_id', $group->id)
            ->whereNot(fn (Builder $query) => $this->onSharedEntry($query, 'subject'))
            ->delete();

        $group->activities()->delete();

        PriceObservation::query()
            ->where('group_id', $group->id)
            ->whereNotIn('product_id', $this->sharedProducts())
            ->delete();
    }

    /**
     * Restricts a note, document or activity query to shared manufacturers
     * and products.
     */
    private function onSharedEntry(Builder $query, string $morph): void
    {
        $query
            ->where(fn (Builder $query) => $query
                ->where("{$morph}_type", (new Product)->getMorphClass())
                ->whereIn("{$morph}_id", $this->sharedProducts()))
            ->orWhere(fn (Builder $query) => $query
                ->where("{$morph}_type", (new Manufacturer)->getMorphClass())
                ->whereIn("{$morph}_id", Manufacturer::query()->where('visibility', Visibility::Public)->select('id')));
    }

    /**
     * @return Builder<Product>
     */
    private function sharedProducts(): Builder
    {
        return Product::withTrashed()->where('visibility', Visibility::Public)->select('id');
    }

    /**
     * @param  Builder<Attachment>  $query
     */
    private function deleteAttachments(Builder $query): void
    {
        $attachments = $query->get();

        foreach ($attachments as $attachment) {
            $this->orphanedFiles[] = ['disk' => $attachment->disk, 'path' => $attachment->path];
        }

        Attachment::query()->whereKey($attachments->modelKeys())->delete();
    }

    /**
     * Filament scopes rounds to the current tenant — dissolving needs to
     * look past that, e.g. at the rounds of other groups.
     *
     * @return Builder<Round>
     */
    private function rounds(): Builder
    {
        return Round::withoutGlobalScopes();
    }

    /**
     * @return Collection<int, Product>
     */
    private function ownedProducts(Group $group): Collection
    {
        return Product::withTrashed()->where('group_id', $group->id)->get();
    }

    /**
     * @return Collection<int, Manufacturer>
     */
    private function ownedManufacturers(Group $group): Collection
    {
        return Manufacturer::query()->where('group_id', $group->id)->get();
    }

    /**
     * @param  Collection<int, User>  $formerMembers
     * @return array{notified: int, failed: int}
     */
    private function notify(Collection $formerMembers, string $groupName, User $by): array
    {
        $failed = 0;

        foreach ($formerMembers as $member) {
            try {
                Mail::to($member)->send(new GroupDissolvedMail($groupName, $by));
            } catch (Throwable $exception) {
                report($exception);
                $failed++;
            }
        }

        return ['notified' => $formerMembers->count() - $failed, 'failed' => $failed];
    }

    private function ensure(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['group' => $message]);
        }
    }
}
