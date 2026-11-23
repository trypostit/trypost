<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DataDeletionRequest\Status;
use App\Enums\SocialAccount\MetaApp;
use Database\Factories\DataDeletionRequestFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A network's request to delete what TryPost stored for one of its users,
 * received through a Meta app's Data Deletion callback. It keeps no trace of
 * who asked: the user id travels only in the queued job that deletes their
 * data, and the row holds the code Meta shows them with the outcome.
 */
class DataDeletionRequest extends Model
{
    /** @use HasFactory<DataDeletionRequestFactory> */
    use HasFactory, HasUuids;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => Status::DEFAULT,
        'channels_affected' => 0,
    ];

    protected $fillable = [
        'app',
        'confirmation_code',
        'status',
        'channels_affected',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'app' => MetaApp::class,
            'status' => Status::class,
            'channels_affected' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    public static function open(MetaApp $app): self
    {
        return self::create([
            'app' => $app,
            'confirmation_code' => Str::lower(Str::random(32)),
        ]);
    }

    public function complete(): void
    {
        $this->update([
            'status' => Status::Completed,
            'completed_at' => now(),
        ]);
    }
}
