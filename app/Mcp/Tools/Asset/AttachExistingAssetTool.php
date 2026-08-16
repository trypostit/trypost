<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Actions\Media\FindWorkspaceAsset;
use App\Actions\Post\AttachExistingAsset;
use App\Http\Requests\Mcp\Asset\AttachExistingAssetRequest;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Post;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Attach an existing Asset Library item to a draft or scheduled post in the current workspace. Repeating the same post_id and asset_id does not duplicate the media.')]
class AttachExistingAssetTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate((new AttachExistingAssetRequest)->rules());

        $post = Post::where('workspace_id', $request->user()?->current_workspace_id)
            ->find(data_get($validated, 'post_id'));

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Not authorized to update this post.')) {
            return $denied;
        }

        if (PostStatusRules::blocksEditing($post)) {
            return Response::error(PostStatusRules::editBlockedMessage());
        }

        $workspace = $request->user()?->currentWorkspace;

        if ($workspace === null) {
            return Response::error('Asset not found.');
        }

        $asset = FindWorkspaceAsset::execute($workspace, data_get($validated, 'asset_id'));

        if (! $asset) {
            return Response::error('Asset not found.');
        }

        if (! in_array($asset->type, $post->allowedMediaTypes(), true)) {
            return Response::error('No enabled platform on this post accepts this media type.');
        }

        AttachExistingAsset::execute(
            $post,
            $asset,
            data_get($validated, 'alt'),
        );

        $post->refresh()->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured([
            'post' => (new PostResource($post))->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the draft or scheduled post to update.'),
            'asset_id' => $schema->string()->required()->description('UUID of an Asset Library media item in the current workspace.'),
            'alt' => $schema->string()->description('Optional accessibility alt text for image assets. Ignored for videos and documents.'),
        ];
    }
}
