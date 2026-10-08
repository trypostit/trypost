<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Actions\Post\AppendPostMedia;
use App\Dto\MediaItem;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Support\Requests\Post\PostMediaRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Attach a file uploaded with request-media-upload-tool to a post. The upload_token is the value request-media-upload-tool returned; the file is resolved by that token within the current workspace, then appended after the post media (to reorder or remove media, call update-post-tool with the media ids in the new order). The file type must be one the post channel accepts (allowed_media_types in list-content-types-tool). Posts that are publishing, published or failed cannot change; a scheduled post edited by a member who needs approval goes back to pending_approval. Size, video duration, GIF and MOV caps per content_type (see list-content-types-tool) are checked when the post is scheduled or published, not here. Media is added to the post itself; to give a thread reply its own media, pass the upload_token in meta.thread_replies with update-post-tool.')]
class AttachMediaFromUploadTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $postId = data_get($request->validate(['post_id' => ['required', 'uuid']]), 'post_id');
        $workspaceId = $request->user()?->current_workspace_id;

        $post = $workspaceId
            ? Post::where('workspace_id', $workspaceId)->find($postId)
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Post not found.')) {
            return $denied;
        }

        $validated = $request->validate(PostMediaRequestRules::attachFromUpload());

        $media = ResolveWorkspaceMedia::byUploadTokens($post->workspace, [(string) data_get($validated, 'upload_token')])->first();

        if (! $media) {
            return Response::error(__('posts.errors.media_expired'));
        }

        if ($violation = PostMediaRequestRules::typeViolation($post, $media->type)) {
            return Response::error($violation);
        }

        try {
            AppendPostMedia::execute($post, [MediaItem::fromMedia($media, data_get($validated, 'alt'))->toArray()], $request->user());
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        $post->refresh()->load(['socialAccount', 'labels']);

        return Response::structured([
            'post' => (new PostResource($post))->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post to attach the uploaded media to.'),
            'upload_token' => $schema->string()->required()->description('upload_token returned by request-media-upload-tool, after the file was sent to its upload_url.'),
            'alt' => $schema->string()->description('Optional accessibility alt text for the media (applies to images).'),
        ];
    }
}
