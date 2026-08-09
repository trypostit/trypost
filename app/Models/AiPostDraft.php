<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Ai\DraftStatus;
use Database\Factories\AiPostDraftFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intermediate, editable structure of an AI post between the "pre-generate text"
 * phase and the "generate final images" phase. The user reviews/edits the
 * `structured` payload before the images are rendered from it.
 */
class AiPostDraft extends Model
{
    /** @use HasFactory<AiPostDraftFactory> */
    use HasFactory, HasUuids;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'social_account_id',
        'format',
        'template',
        'image_count',
        'apply_brand_visuals',
        'scheduled_date',
        'prompt',
        'structured',
        'status',
        'post_id',
        'error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'structured' => 'array',
            'status' => DraftStatus::class,
            'scheduled_date' => 'date',
            'image_count' => 'integer',
            'apply_brand_visuals' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
