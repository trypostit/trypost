<?php

declare(strict_types=1);

namespace App\Actions\Post;

use App\Enums\Post\Action as PostAction;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\PostStatusRules;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

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
            DB::transaction(function () use ($post, $data) {
                $post->postPlatforms()->update(['enabled' => false]);

                foreach (data_get($data, 'platforms', []) as $platformData) {
                    $updateData = ['enabled' => true];

                    if (data_get($platformData, 'content_type') !== null) {
                        $updateData['content_type'] = data_get($platformData, 'content_type');
                    }

                    if (data_get($platformData, 'meta') !== null) {
                        $postPlatform = $post->postPlatforms()->where('id', data_get($platformData, 'id'))->first();

                        if ($postPlatform) {
                            $updateData['meta'] = array_merge($postPlatform->meta ?? [], data_get($platformData, 'meta'));
                        }
                    }

                    $post->postPlatforms()
                        ->where('id', data_get($platformData, 'id'))
                        ->update($updateData);
                }
            });
        }

        if ($status === PostStatus::Publishing->value) {
            $post->update(['scheduled_at' => now()]);
            PublishPost::dispatch($post);

            return ['post' => $post, 'action' => PostAction::Publishing];
        }

        if ($status === PostStatus::Scheduled->value) {
            return ['post' => $post, 'action' => PostAction::Scheduled];
        }

        return ['post' => $post, 'action' => null];
    }
}
