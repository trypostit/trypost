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

#[Description('Reuse an existing Asset Library item on a post in the current workspace (the workspace bound to this MCP token). Does not upload a new file — for new media use request-media-upload-tool then attach-media-from-upload-tool, or attach-media-from-url-tool. Discover ids with list-assets-tool or get-asset-tool. The post must be draft or scheduled; published, partially published, failed, and publishing posts are rejected. The asset must already be in this workspace library. The asset type must be accepted by every enabled platform on the post (e.g. TikTok-only posts reject images). Repeating the same post_id and asset_id does not duplicate the media or change alt. Optional alt overrides image alt text only (ignored for video/document), maximum 2000 characters; omit to keep the library item\'s existing alt_text. Requires permission to update the post. Returns the updated post (same shape as other post tools).')]
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
            return Response::error(AttachExistingAsset::ASSET_NOT_FOUND_MESSAGE);
        }

        $asset = FindWorkspaceAsset::execute($workspace, data_get($validated, 'asset_id'));

        if (! $asset) {
            return Response::error(AttachExistingAsset::ASSET_NOT_FOUND_MESSAGE);
        }

        if (! in_array($asset->type, $post->allowedMediaTypes(), true)) {
            return Response::error(AttachExistingAsset::UNSUPPORTED_TYPE_MESSAGE);
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
            'post_id' => $schema->string()->required()->description('Required UUID of the post to update. Must belong to the current workspace and be draft or scheduled. Other workspaces return "Post not found." Published, partially published, failed, or publishing posts are rejected.'),
            'asset_id' => $schema->string()->required()->description('Required UUID of an Asset Library item in the current workspace (from list-assets-tool or get-asset-tool). Missing, other-workspace, or non-library media returns "Asset not found." Type must be allowed by the post\'s enabled platforms.'),
            'alt' => $schema->string()->description('Optional accessibility alt text for images (ignored for video and document). Maximum 2000 characters. When set, replaces any alt_text already on the library item. Omit to keep the library item\'s existing alt_text.'),
        ];
    }
}
