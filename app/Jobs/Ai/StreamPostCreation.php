<?php

declare(strict_types=1);

namespace App\Jobs\Ai;

use App\Actions\Post\CreatePost;
use App\Ai\Templates\AiTemplateRegistry;
use App\Ai\Templates\GeneratedPost;
use App\Ai\Templates\TemplateContext;
use App\Enums\Ai\DraftStatus;
use App\Enums\Notification\Channel as NotificationChannel;
use App\Enums\Notification\Type as NotificationType;
use App\Enums\Post\CreatedVia;
use App\Enums\PostPlatform\ContentType;
use App\Events\Ai\PostCreationReady;
use App\Jobs\SendNotification;
use App\Models\AiPostDraft;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\PostContentComposer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class StreamPostCreation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public string $userId,
        public string $creationId,
        public string $workspaceId,
        public string $format,
        public ?string $socialAccountId,
        public int $imageCount,
        public string $prompt,
        public ?string $date = null,
        public string $template = 'image_card',
        public bool $applyBrandVisuals = true,
        /** Review flow (phase B): when set, skip text generation and render this reviewed structure. */
        public ?array $preparedStructured = null,
        /** Links this run to an AiPostDraft to mark completed/failed. */
        public ?string $draftId = null,
    ) {
        $this->onQueue('ai');
    }

    public function handle(): void
    {
        $workspace = Workspace::findOrFail($this->workspaceId);
        $socialAccount = $this->socialAccountId ? SocialAccount::find($this->socialAccountId) : null;

        $style = app(AiTemplateRegistry::class)->find($this->template);

        $isCarousel = $this->format === ContentType::CAROUSEL_FORMAT;

        $context = new TemplateContext(
            workspace: $workspace,
            socialAccount: $socialAccount,
            format: $this->format,
            imageCount: $this->imageCount,
            isCarousel: $isCarousel,
            applyBrandVisuals: $this->applyBrandVisuals,
        );

        try {
            // The review flow (phase B) passes the reviewed structure: skip text
            // generation and render images from exactly what the user approved.
            $structured = $this->preparedStructured
                ?? app(PostContentComposer::class)->compose(
                    workspace: $workspace,
                    format: $this->format,
                    imageCount: $this->imageCount,
                    prompt: $this->prompt,
                    style: $style,
                    context: $context,
                    userId: $this->userId,
                );

            $generated = $style->assemble($structured, $context);
            $post = $this->createPostFromGenerated($workspace, $generated, $socialAccount);

            if ($this->draftId !== null) {
                AiPostDraft::whereKey($this->draftId)->update([
                    'post_id' => $post->id,
                    'status' => DraftStatus::Completed,
                ]);
            }

            $this->notifyReady($workspace, $post);
        } catch (\Throwable $e) {
            Log::error('StreamPostCreation failed', [
                'creation_id' => $this->creationId,
                'error' => $e->getMessage(),
            ]);

            if ($this->draftId !== null) {
                AiPostDraft::whereKey($this->draftId)->update([
                    'status' => DraftStatus::Failed,
                    'error' => $e->getMessage(),
                ]);
            }

            PostCreationReady::dispatch($this->userId, $this->creationId, null, $e->getMessage());

            throw $e;
        }
    }

    private function createPostFromGenerated(Workspace $workspace, GeneratedPost $generated, ?SocialAccount $socialAccount): Post
    {
        $user = User::findOrFail($this->userId);

        $post = CreatePost::execute($workspace, $user, [
            'content' => $generated->content,
            'media' => $generated->media,
            'date' => $this->date,
            'created_via' => CreatedVia::Web,
        ]);

        if ($generated->contentType && $socialAccount) {
            $aspectRatio = $this->aspectRatioFor($generated->contentType);

            $post->postPlatforms()
                ->where('social_account_id', $socialAccount->id)
                ->each(function ($platform) use ($aspectRatio, $generated): void {
                    $meta = $platform->meta ?? [];
                    if ($aspectRatio !== null) {
                        $meta['aspect_ratio'] = $aspectRatio;
                    }
                    $platform->meta = $meta;
                    $platform->content_type = $generated->contentType->value;
                    $platform->enabled = true;
                    $platform->save();
                });
        }

        return $post;
    }

    private function notifyReady(Workspace $workspace, Post $post): void
    {
        PostCreationReady::dispatch(
            userId: $this->userId,
            creationId: $this->creationId,
            postId: $post->id,
        );

        $user = User::findOrFail($this->userId);

        SendNotification::dispatch(
            user: $user,
            workspaceId: $workspace->id,
            type: NotificationType::PostReady,
            channel: NotificationChannel::InApp,
            title: trans('notifications.post_ready.title', [], $workspace->content_language),
            body: trans('notifications.post_ready.body', [], $workspace->content_language),
            data: ['post_id' => $post->id],
        );
    }

    private function aspectRatioFor(ContentType $type): ?string
    {
        $dims = $type->aiImageDimensions();
        $ratio = $dims['width'] / $dims['height'];

        return match (true) {
            abs($ratio - 1.0) < 0.01 => '1:1',
            abs($ratio - 4 / 5) < 0.01 => '4:5',
            abs($ratio - 16 / 9) < 0.01 => '16:9',
            default => null,
        };
    }
}
