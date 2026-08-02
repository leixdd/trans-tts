<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $visitor_id
 * @property string $status
 * @property string $source_text
 * @property string $target_language
 * @property string|null $speaker_reference_id
 * @property string|null $translation
 * @property string|null $stream_debug
 * @property string|null $worker_logs
 * @property string|null $audio_path
 * @property string|null $error
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class TranslationTurn extends Model
{
    use HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'visitor_id',
        'status',
        'source_text',
        'target_language',
        'speaker_reference_id',
        'translation',
        'stream_debug',
        'worker_logs',
        'audio_path',
        'error',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
        ];
    }
}
