<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Enums\Media\Type as MediaType;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Issue a one-shot signed POST URL that lets the user upload a local file (image, video, or PDF document) directly to this workspace. Size caps match web/API media limits — see max_bytes_by_type (max_bytes is the overall ceiling, equal to the video cap). Returns an upload_token, upload_url, max_bytes, and max_bytes_by_type. Hand the URL to the user (e.g. as a curl command with `-F media=@path/to/file`) or to the MCP client. After upload, call AttachMediaFromUploadTool(post_id, upload_token) to attach the result to a post.')]
class RequestMediaUploadTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to upload media.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $workspaceId = $workspace->id;

        $ttlMinutes = (int) config('trypost.media.signed_upload_url_ttl_minutes');

        $token = (string) Str::uuid();
        $expiresAt = CarbonImmutable::now()->addMinutes($ttlMinutes);

        $uploadUrl = URL::temporarySignedRoute(
            'api.uploads.store',
            $expiresAt,
            ['token' => $token, 'workspace_id' => $workspaceId],
        );

        return Response::structured([
            'upload_token' => $token,
            'upload_url' => $uploadUrl,
            'expires_at' => $expiresAt->toIso8601String(),
            'max_bytes' => MediaType::Video->maxSizeInBytes(),
            'max_bytes_by_type' => [
                'image' => MediaType::Image->maxSizeInBytes(),
                'video' => MediaType::Video->maxSizeInBytes(),
                'document' => MediaType::Document->maxSizeInBytes(),
            ],
            'field_name' => 'media',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
