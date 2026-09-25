<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

class Attachment extends Model
{
    protected $fillable = [
        'attachable_type',
        'attachable_id',
        'uploaded_by_user_id',
        'group_id',
        'disk',
        'path',
        'original_name',
        'mime_type',
        'size_bytes',
    ];

    public function attachable(): MorphTo
    {
        return $this->morphTo();
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    /**
     * Download link — the file itself is private and handed out by the
     * AttachmentController after an authorization check.
     */
    public function url(): string
    {
        return route('attachments.download', $this);
    }

    protected static function booted(): void
    {
        static::deleted(function (self $attachment): void {
            Storage::disk($attachment->disk)->delete($attachment->path);
        });
    }

    public function humanSize(): string
    {
        $bytes = (int) $this->size_bytes;
        $units = ['B', 'KB', 'MB', 'GB'];
        for ($i = 0; $bytes >= 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }

        return round($bytes, 1).' '.$units[$i];
    }
}
