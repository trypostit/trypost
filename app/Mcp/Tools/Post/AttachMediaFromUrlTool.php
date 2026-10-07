<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Services\Post\MediaAttacher;
use App\Support\Requests\Post\PostMediaRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Download images, videos, or PDF documents from public URLs and attach them to a post. Each URL is fetched, stored, and registered as a Media record on the workspace. The media is appended after the post\'s current media. Each URL must serve the file itself: redirects are not followed, and web pages, private-network hosts and files over the type\'s size ceiling are refused. Only the types the post\'s channel accepts are kept. URLs that could not be attached are listed in failed_urls, and failures gives the reason of each (unreachable, type_not_allowed, too_large, host_not_allowed) with a message. Video duration is measured on the server. Per-network size, duration, GIF and MOV caps (see list-content-types-tool) are enforced when the post is scheduled or published. Supported files: JPEG, PNG, GIF and WebP images (HEIC and HEIF too when the server can convert them), MP4 and MOV videos, and PDF. Each download must finish within 20 seconds and fit the server limits (by default 10 MB and 67 megapixels for an image, 1 GB for a video, 100 MB for a PDF). Media is added to the post itself; to give a thread reply its own media, pass it in meta.thread_replies with update-post-tool.')]
class AttachMediaFromUrlTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $post = Post::where('workspace_id', $request->user()?->current_workspace_id)
            ->find(data_get($request->validate(['post_id' => ['required', 'uuid']]), 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Post not found.')) {
            return $denied;
        }

        $validated = $request->validate(PostMediaRequestRules::attachFromUrl());

        try {
            $result = app(MediaAttacher::class)->attachFromUrls(
                $post,
                data_get($validated, 'urls', []),
                $request->user(),
            );
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        $post->refresh()->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured([
            'post' => (new PostResource($post))->resolve(),
            'attached_count' => count($result['attached']),
            'failed_urls' => $result['failed'],
            'failures' => $result['failures'],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post to attach media to.'),
            'urls' => $schema->array()
                ->items($schema->object(fn ($u) => [
                    'url' => $u->string()->required()->description('Public HTTP/HTTPS URL of an image, video, or PDF.'),
                    'alt' => $u->string()->description('Optional accessibility alt text for the image (ignored for video/PDF, which have no alt text).'),
                ]))
                ->required()
                ->description('Media to attach. Max 10 per call. Allowed types: image/jpeg, image/png, image/gif, image/webp, video/mp4, video/quicktime, application/pdf. The server per-type size ceilings apply on download; stricter per-network caps apply at schedule/publish.'),
        ];
    }
}
