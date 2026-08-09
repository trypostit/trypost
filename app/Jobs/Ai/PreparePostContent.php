<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Ai\Templates\AiTemplateRegistry;
use App\Ai\Templates\TemplateContext;
use App\Enums\Ai\DraftStatus;
use App\Enums\PostPlatform\ContentType;
use App\Events\Ai\PostContentPrepared;
use App\Models\AiPostDraft;
use App\Models\SocialAccount;
use App\Services\Ai\PostContentComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Phase A of the review flow: pre-generates ONLY the structured text (generator
 * + humanizer, no images) and stores it on the draft for the user to review.
 * Phase B (StreamPostCreation with `preparedStructured`) renders the images from
 * the reviewed structure.
 */
class PreparePostContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public string $draftId)
    {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $draft = AiPostDraft::find($this->draftId);

        if (! $draft) {
            return;
        }

        $workspace = $draft->workspace;
        $socialAccount = $draft->social_account_id ? SocialAccount::find($draft->social_account_id) : null;
        $style = app(AiTemplateRegistry::class)->find($draft->template);
        $isCarousel = $draft->format === ContentType::CAROUSEL_FORMAT;

        $context = new TemplateContext(
            workspace: $workspace,
            socialAccount: $socialAccount,
            format: $draft->format,
            imageCount: $draft->image_count,
            isCarousel: $isCarousel,
            applyBrandVisuals: $draft->apply_brand_visuals,
        );

        try {
            $structured = app(PostContentComposer::class)->compose(
                workspace: $workspace,
                format: $draft->format,
                imageCount: $draft->image_count,
                prompt: $draft->prompt,
                style: $style,
                context: $context,
                userId: $draft->user_id,
            );

            $draft->update([
                'structured' => $structured,
                'status' => DraftStatus::Ready,
            ]);

            PostContentPrepared::dispatch($draft->user_id, $draft->id);
        } catch (\Throwable $e) {
            Log::error('PreparePostContent failed', [
                'draft_id' => $draft->id,
                'error' => $e->getMessage(),
            ]);

            $draft->update([
                'status' => DraftStatus::Failed,
                'error' => $e->getMessage(),
            ]);

            PostContentPrepared::dispatch($draft->user_id, $draft->id, $e->getMessage());

            throw $e;
        }
    }
}
