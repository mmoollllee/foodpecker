<?php

use App\Enums\NotificationKind;
use App\Enums\RoundPhase;
use App\Models\User;
use App\Services\Notifications\DraftBuilder;
use App\Services\Notifications\DraftSender;
use Illuminate\Mail\PendingMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    ['round' => $this->round, 'lead' => $this->lead, 'members' => [$this->anna, $this->ben]] = roundScenario(2, RoundPhase::Negotiating);
    $this->draft = app(DraftBuilder::class)->buildDraft($this->round, NotificationKind::NegotiationStarted, $this->lead);
    $this->sender = app(DraftSender::class);
});

it('sends to the participants and marks the draft as sent', function () {
    Mail::fake();

    $result = $this->sender->send($this->draft, $this->sender->recipients($this->round, false), $this->lead, 'https://example.test/runde');

    expect($result)->toBe(['sent' => 3, 'failed' => []])
        ->and($this->draft->fresh()->isSent())->toBeTrue()
        ->and($this->draft->fresh()->recipient_count)->toBe(3);
});

it('keeps sending when one address fails and never sends a draft twice', function () {
    $this->ben->update(['email' => 'broken@example.org']);

    Mail::shouldReceive('to')->andReturnUsing(function (User $recipient) {
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andReturnUsing(function () use ($recipient) {
            if ($recipient->email === 'broken@example.org') {
                throw new RuntimeException('550 mailbox unavailable');
            }

            return null;
        });

        return $pending;
    });

    $result = $this->sender->send($this->draft, $this->sender->recipients($this->round, false), $this->lead, 'https://example.test/runde');

    expect($result['sent'])->toBe(2)
        ->and($result['failed'])->toBe([$this->ben->fresh()->fullName()])
        ->and($this->draft->fresh()->isSent())->toBeTrue();

    expect(fn () => $this->sender->send($this->draft->fresh(), $this->sender->recipients($this->round, false), $this->lead, 'https://example.test/runde'))
        ->toThrow(ValidationException::class, 'Diese Benachrichtigung wurde bereits verschickt.');
});

it('keeps the draft unsent when no mail could be delivered', function () {
    Mail::shouldReceive('to')->andReturnUsing(function () {
        $pending = Mockery::mock(PendingMail::class);
        $pending->shouldReceive('send')->andThrow(new RuntimeException('SMTP down'));

        return $pending;
    });

    expect(fn () => $this->sender->send($this->draft, $this->sender->recipients($this->round, false), $this->lead, 'https://example.test/runde'))
        ->toThrow(ValidationException::class, 'Die Mails konnten nicht verschickt werden.');

    expect($this->draft->fresh()->isSent())->toBeFalse();
});

it('addresses all group members while the round is open for shopping', function () {
    $newcomer = User::factory()->create();
    $this->round->group->members()->attach($newcomer->id, ['role' => 'participant']);
    $this->round->update(['phase' => RoundPhase::Shopping]);

    expect($this->sender->defaultsToAllMembers($this->round))->toBeTrue()
        ->and($this->sender->recipients($this->round, true)->pluck('id'))->toContain($newcomer->id)
        ->and($this->sender->recipients($this->round, false)->pluck('id'))->not->toContain($newcomer->id);
});
