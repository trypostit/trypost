<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Enums\PostPlatform\Status as PlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\Workspace;
use App\Support\PostCompositionValidator;
use App\Support\PostStatusRules;
use App\Support\Social\AbandonGoogleBusinessReview;
use App\Support\Social\GoogleBusinessDerivativeCleaner;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePost
{
    /**
     * @return array{post: Post, action: PostAction|null}
     */
    public static function execute(Workspace $workspace, Post $post, array $data): array
    {
        if (PostStatusRules::blocksEditing($post)) {
            return ['post' => $post, 'action' => PostAction::Finalized];
        }

        if (array_key_exists('social_account_id', $data)) {
            throw ValidationException::withMessages(['social_account_id' => __('validation.in', ['attribute' => 'social account'])]);
        }

        if ($post->postPlatforms()->enabled()->count() === 1) {
            $selectedTarget = $post->postPlatforms()->enabled()->sole();
            if (array_key_exists('platforms', $data)) {
                if (count($data['platforms']) !== 1 || data_get($data, 'platforms.0.id') !== $selectedTarget->id
                    || array_key_exists('content_type', $data) || array_key_exists('meta', $data)) {
                    throw ValidationException::withMessages(['platforms' => __('validation.in', ['attribute' => 'platforms'])]);
                }

                $data = [
                    ...Arr::except($data, ['platforms']),
                    ...Arr::only($data['platforms'][0], ['content_type', 'meta']),
                ];
            }

            return self::updateChannelPost($workspace, $post, $data);
        }

        if (array_key_exists('content_type', $data) || array_key_exists('meta', $data)) {
            return self::updateChannelPost($workspace, $post, $data);
        }

        return DB::transaction(function () use ($post, $data): array {
            $scheduledAt = $post->scheduled_at;
            if (data_get($data, 'scheduled_at')) {
                $scheduledAt = Carbon::parse(data_get($data, 'scheduled_at'))->utc();
            }

            $status = data_get($data, 'status', $post->status);

            $post->update([
                'content' => data_get($data, 'content', $post->content),
                'media' => data_get($data, 'media', $post->media),
                'status' => $status === PostStatus::Publishing->value ? PostStatus::Publishing : $status,
                'scheduled_at' => $scheduledAt,
            ]);

            if (Arr::has($data, 'label_ids')) {
                $post->labels()->sync(data_get($data, 'label_ids', []));
            }

            if (Arr::has($data, 'platforms')) {
                $post->postPlatforms()->update(['enabled' => false]);

                foreach (data_get($data, 'platforms', []) as $platformData) {
                    $updateData = ['enabled' => true];

                    if (data_get($platformData, 'content_type') !== null) {
                        $updateData['content_type'] = data_get($platformData, 'content_type');
                    }

                    if (data_get($platformData, 'meta') !== null) {
                        $postPlatform = $post->postPlatforms()->where('id', data_get($platformData, 'id'))->first();

                        if ($postPlatform) {
                            $updateData['meta'] = array_filter(
                                array_merge($postPlatform->meta ?? [], data_get($platformData, 'meta') ?? []),
                                fn (mixed $value): bool => $value !== null,
                            );
                        }
                    }

                    $post->postPlatforms()
                        ->where('id', data_get($platformData, 'id'))
                        ->update($updateData);
                }

                $post->postPlatforms()
                    ->disabled()
                    ->where('platform', Platform::GoogleBusiness)
                    ->where('status', PlatformStatus::PendingReview)
                    ->get()
                    ->each(fn (PostPlatform $platform) => AbandonGoogleBusinessReview::execute(
                        $platform,
                        __('posts.errors.target_disabled'),
                        ['category' => 'target_disabled'],
                    ));

                $disabledGoogleBusinessIds = $post->postPlatforms()
                    ->disabled()
                    ->where('platform', Platform::GoogleBusiness)
                    ->pluck('id');

                DB::afterCommit(function () use ($disabledGoogleBusinessIds): void {
                    $disabledGoogleBusinessIds->each(
                        fn (string $id) => app(GoogleBusinessDerivativeCleaner::class)->cleanup($id),
                    );
                });
            }

            if ($status === PostStatus::Publishing->value) {
                $post->update(['scheduled_at' => now()]);
                PublishPost::dispatch($post)->afterCommit();

                return ['post' => $post, 'action' => PostAction::Publishing];
            }

            if ($status === PostStatus::Scheduled->value) {
                return ['post' => $post, 'action' => PostAction::Scheduled];
            }

            return ['post' => $post, 'action' => null];
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{post: Post, action: PostAction|null}
     */
    private static function updateChannelPost(Workspace $workspace, Post $post, array $data): array
    {
        if (array_key_exists('platforms', $data)) {
            throw ValidationException::withMessages(['platforms' => __('validation.in', ['attribute' => 'platforms'])]);
        }

        if ($post->postPlatforms()->enabled()->count() !== 1) {
            throw ValidationException::withMessages(['post' => PostStatusRules::editBlockedMessage()]);
        }

        $target = $post->postPlatforms()->enabled()->sole();
        $meta = array_filter(
            array_merge($target->meta ?? [], $data['meta'] ?? []),
            fn (mixed $value): bool => $value !== null,
        );
        $status = $data['status'] ?? $post->status->value;
        $scheduledAt = array_key_exists('scheduled_at', $data)
            ? $data['scheduled_at']
            : $post->scheduled_at?->toIso8601String();
        $resolved = PostCompositionValidator::validate($workspace, [
            'status' => $status,
            'content' => array_key_exists('content', $data) ? $data['content'] : $post->content,
            'media' => $data['media'] ?? $post->media ?? [],
            'scheduled_at' => $scheduledAt,
            'label_ids' => $data['label_ids'] ?? $post->labels()->pluck('workspace_labels.id')->all(),
            'destinations' => [[
                'social_account_id' => $target->social_account_id,
                'content_type' => $data['content_type'] ?? $target->content_type->value,
                'meta' => $meta,
            ]],
        ], $post->media ?? []);

        return DB::transaction(function () use ($post, $target, $data, $resolved, $meta, $status, $scheduledAt): array {
            $destination = $resolved['destinations'][0];
            $post->update([
                'content' => $destination['content'],
                'media' => $destination['media'],
                'status' => $status,
                'scheduled_at' => $scheduledAt ? Carbon::parse($scheduledAt)->utc() : null,
            ]);
            $target->update([
                'content_type' => $destination['content_type'],
                'meta' => $meta,
            ]);

            if (array_key_exists('label_ids', $data)) {
                $post->labels()->sync($data['label_ids']);
            }

            if ($status === PostStatus::Publishing->value) {
                $post->update(['scheduled_at' => now()]);
                PublishPost::dispatch($post)->afterCommit();

                return ['post' => $post, 'action' => PostAction::Publishing];
            }

            if ($status === PostStatus::Scheduled->value) {
                return ['post' => $post, 'action' => PostAction::Scheduled];
            }

            return ['post' => $post, 'action' => null];
        });
    }
}
