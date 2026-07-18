<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Http\Resources\Api\PostResource;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\PostMediaRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Attach a Media uploaded via RequestMediaUploadTool to a post. The upload_token is the value returned by RequestMediaUploadTool; the Media is resolved by that token within the current workspace, then appended to the post.')]
class AttachMediaFromUploadTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'upload_token' => ['required', 'uuid'],
            'alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ]);

        $workspaceId = $request->user()->current_workspace_id;

        $post = Post::where('workspace_id', $workspaceId)
            ->find(data_get($validated, 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        $media = Media::query()
            ->where('upload_token', data_get($validated, 'upload_token'))
            ->where('mediable_type', (new Workspace)->getMorphClass())
            ->where('mediable_id', $workspaceId)
            ->first();

        if (! $media) {
            return Response::error('Upload not found.');
        }

        if (! in_array($media->type, $post->allowedMediaTypes(), true)) {
            return Response::error('No enabled platform on this post accepts this media type.');
        }

        $item = [
            'id' => $media->id,
            'path' => $media->path,
            'url' => $media->url,
            'type' => $media->type,
            'mime_type' => $media->mime_type,
            'original_filename' => $media->original_filename,
        ];

        if (($alt = data_get($validated, 'alt')) !== null && $media->isImage()) {
            $item['meta'] = ['alt_text' => $alt];
        }

        $post->appendMedia([$item]);

        $post->refresh()->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured([
            'post' => (new PostResource($post))->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post to attach the uploaded media to.'),
            'upload_token' => $schema->string()->required()->description('upload_token returned by RequestMediaUploadTool, after the user has POSTed the file to the upload_url.'),
            'alt' => $schema->string()->description('Optional accessibility alt text for the media (applies to images).'),
        ];
    }
}
