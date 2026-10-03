<?php

declare(strict_types=1);

namespace App\Jobs\Repurpose;

use App\Actions\Media\DeleteOwnedMedia;
use App\Actions\Post\Approval\NotifyApprovalRequested;
use App\Actions\Post\CreateChannelPost;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\ContentType;
use App\Enums\Repurpose\ItemReason;
use App\Enums\Repurpose\ItemStatus;
use App\Enums\Repurpose\PublishMode;
use App\Exceptions\Repurpose\SourceDownloadException;
use App\Models\Media;
use App\Models\Post;
use App\Models\RepurposeItem;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Post\MediaAttacher;
use App\Services\Repurpose\CaptionAdapter;
use App\Services\Social\TokenRedactor;
use App\Support\Media\MediaCopyBatch;
use App\Support\PostApproval;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class ProcessRepurposeItem implements ShouldBeUnique, ShouldQueue
{
    public int $uniqueFor = 3600;

    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public RepurposeItem $item,
        public string $downloadUrl,
        public string $caption,
    ) {}

    /**
     * @return array<int, int>
     */
    public function backoff(): array
    {
        return [60, 300, 900];
    }

    public function uniqueId(): string
    {
        return $this->item->id;
    }

    public function handle(MediaAttacher $media, CaptionAdapter $captions): void
    {
        if ($this->item->status->isTerminal()) {
            return;
        }

        $repurpose = $this->item->repurpose;
        $workspace = $repurpose->workspace;
        $user = $repurpose->user ?? $workspace->owner;

        if ($user === null) {
            $this->item->update(['status' => ItemStatus::Failed, 'reason' => ItemReason::PostCreationFailed]);

            return;
        }

        if ($this->item->posts()->where('status', '!=', PostStatus::Draft)->exists()) {
            $this->item->update(['status' => ItemStatus::Published]);

            return;
        }

        $this->item->posts()->each(fn (Post $post) => self::deletePost($post));

        $this->item->update(['status' => ItemStatus::Processing]);

        $targets = [];

        foreach ($repurpose->destinations as $destination) {
            $account = $workspace->socialAccounts()->find(data_get($destination, 'social_account_id'));

            if ($account !== null && $account->platform->acceptsRepurposeDestination()) {
                $targets[] = [
                    'account' => $account,
                    'destination' => $destination,
                    'content' => e($captions->adapt($workspace, $user, $this->caption, $account->platform)),
                ];
            }
        }

        if ($targets === []) {
            $this->item->update(['status' => ItemStatus::Failed, 'reason' => ItemReason::NoUsableDestinations]);

            return;
        }

        $upload = $media->hostUpload(
            $workspace,
            Post::allowedMediaTypesFor(collect([data_get($targets, '0.account')->platform])),
            $this->downloadUrl,
        );

        if ($upload === null) {
            throw new SourceDownloadException("Could not download the source video for repurpose item {$this->item->id}.");
        }

        $groupId = (string) Str::uuid7();

        try {
            $posts = MediaCopyBatch::run(fn (MediaCopyBatch $batch): array => array_map(
                fn (array $target): Post => $this->createPost($workspace, $user, $target, $upload, $groupId, $batch),
                $targets,
            ));
        } catch (Throwable $exception) {
            DB::transaction(fn () => DeleteOwnedMedia::forRows([$upload->id]));

            throw $exception;
        }

        if ($repurpose->publish_mode === PublishMode::Draft) {
            $this->item->update(['status' => ItemStatus::Drafted, 'reason' => null, 'error' => null]);

            return;
        }

        $requiresApproval = PostApproval::isRequired($workspace, $user, PostStatus::Scheduled->value);

        DB::transaction(function () use ($posts, $requiresApproval, $user): void {
            foreach ($posts as $post) {
                $post->update($requiresApproval
                    ? [
                        'status' => PostStatus::PendingApproval,
                        'scheduled_at' => null,
                        'schedule_mode' => null,
                        ...PostApproval::transition(PostStatus::Draft, PostStatus::PendingApproval, $user),
                    ]
                    : ['status' => PostStatus::Scheduled, 'scheduled_at' => now(), 'schedule_mode' => ScheduleMode::Custom]);
            }
        });

        if ($requiresApproval) {
            NotifyApprovalRequested::execute(collect($posts), $user);
        }

        $this->item->update(['status' => ItemStatus::Published, 'reason' => null, 'error' => null]);
    }

    public function failed(Throwable $exception): void
    {
        if ($this->item->repurpose?->publish_mode !== PublishMode::Draft) {
            $this->item->posts()
                ->where('status', PostStatus::Draft)
                ->get()
                ->each(fn (Post $post) => self::deletePost($post));
        }

        $this->item->update([
            'status' => ItemStatus::Failed,
            'reason' => $exception instanceof SourceDownloadException ? ItemReason::DownloadFailed : $this->item->reason,
            'error' => $this->safeError($exception),
        ]);
    }

    private static function deletePost(Post $post): void
    {
        DB::transaction(function () use ($post): void {
            DeleteOwnedMedia::forPosts([$post->id]);
            $post->forceDelete();
        });
    }

    private function safeError(Throwable $exception): string
    {
        $message = str_replace($this->downloadUrl, '[source url]', $exception->getMessage());

        return Str::limit((string) TokenRedactor::redact($message), 1000);
    }

    /**
     * The first post moves the downloaded upload; the others copy it.
     *
     * @param  array{account: SocialAccount, destination: array<string, mixed>, content: string}  $target
     */
    private function createPost(Workspace $workspace, User $user, array $target, Media $upload, string $groupId, MediaCopyBatch $batch): Post
    {
        $account = data_get($target, 'account');
        $destination = data_get($target, 'destination');

        $post = CreateChannelPost::execute($workspace, $user, [
            'post_group_id' => $groupId,
            'content' => data_get($target, 'content'),
            'media' => [['id' => $upload->id]],
            'status' => PostStatus::Draft->value,
            'created_via' => CreatedVia::Repurpose,
            'social_account_id' => $account->id,
            'content_type' => data_get($destination, 'content_type') ?? ContentType::defaultFor($account->platform)->value,
            'meta' => data_get($destination, 'meta', []),
            'label_ids' => [],
        ], $batch);

        $post->update(['repurpose_item_id' => $this->item->id]);

        return $post;
    }
}
