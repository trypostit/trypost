<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\BuildPublishPageProps;
use App\Http\Resources\App\PostCardResource;
use App\Models\Post;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostGroupController extends Controller
{
    public function show(Post $post): AnonymousResourceCollection
    {
        $this->authorize('view', $post);

        $posts = Post::query()
            ->where('workspace_id', $post->workspace_id)
            ->when(
                $post->post_group_id !== null,
                fn ($query) => $query->where('post_group_id', $post->post_group_id),
                fn ($query) => $query->whereKey($post->id),
            )
            ->with([
                'postPlatforms' => fn ($platforms) => $platforms->enabled()->with('socialAccount'),
                'user.avatarMedia',
                'labels',
            ])
            ->withCount('notes')
            ->get()
            ->sortBy(fn (Post $sibling): array => [
                $sibling->scheduled_at === null ? 1 : 0,
                $sibling->scheduled_at?->getTimestamp() ?? 0,
                $sibling->postPlatforms->first()?->socialAccount?->position ?? PHP_INT_MAX,
            ])
            ->values();

        BuildPublishPageProps::decorate($posts);
        BuildPublishPageProps::attachMetrics($posts);

        return PostCardResource::collection($posts);
    }
}
