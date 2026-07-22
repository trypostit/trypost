<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Actions\Post\AttachExistingAsset;
use App\Http\Resources\Api\PostResource;
use App\Models\Media;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\PostMediaRules;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Attach an existing Asset Library item to a draft or scheduled post in the current workspace. The operation is idempotent: repeating the same post_id and asset_id never duplicates post media.')]
class AttachExistingAssetTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'post_id' => ['required', 'uuid'],
            'asset_id' => ['required', 'uuid'],
            'alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ]);

        $workspaceId = $request->user()->current_workspace_id;

        $post = Post::where('workspace_id', $workspaceId)
            ->find(data_get($validated, 'post_id'));

        if (! $post) {
            return Response::error('post_not_found');
        }

        if (! Gate::forUser($request->user())->inspect('update', $post)->allowed()) {
            return Response::error('forbidden');
        }

        if (PostStatusRules::blocksEditing($post)) {
            return Response::error('post_not_editable');
        }

        $media = Media::query()
            ->whereKey(data_get($validated, 'asset_id'))
            ->where('mediable_type', (new Workspace)->getMorphClass())
            ->where('mediable_id', $workspaceId)
            ->where('collection', 'assets')
            ->first();

        if (! $media) {
            return Response::error('asset_not_found');
        }

        if (! in_array($media->type, $post->allowedMediaTypes(), true)) {
            return Response::error('media_type_not_allowed');
        }

        $attached = app(AttachExistingAsset::class)->handle(
            $post,
            $media,
            data_get($validated, 'alt'),
        );

        $post->refresh()->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured([
            'asset_id' => $media->id,
            'attached' => $attached,
            'already_attached' => ! $attached,
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
