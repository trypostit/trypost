<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApiKey\CreateApiKeyTool;
use App\Mcp\Tools\ApiKey\DeleteApiKeyTool;
use App\Mcp\Tools\ApiKey\ListApiKeysTool;
use App\Mcp\Tools\Asset\AttachExistingAssetTool;
use App\Mcp\Tools\Asset\GetAssetTool;
use App\Mcp\Tools\Asset\ListAssetsTool;
use App\Mcp\Tools\Label\CreateLabelTool;
use App\Mcp\Tools\Label\DeleteLabelTool;
use App\Mcp\Tools\Label\ListLabelsTool;
use App\Mcp\Tools\Label\UpdateLabelTool;
use App\Mcp\Tools\Platform\ListContentTypesTool;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\DeletePostTool;
use App\Mcp\Tools\Post\GetPostMetricsTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\ListPostsTool;
use App\Mcp\Tools\Post\PreviewPostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Mcp\Tools\Signature\CreateSignatureTool;
use App\Mcp\Tools\Signature\DeleteSignatureTool;
use App\Mcp\Tools\Signature\ListSignaturesTool;
use App\Mcp\Tools\Signature\UpdateSignatureTool;
use App\Mcp\Tools\SocialAccount\ListDiscordChannelsTool;
use App\Mcp\Tools\SocialAccount\ListPinterestBoardsTool;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Mcp\Tools\SocialAccount\ToggleSocialAccountTool;
use App\Mcp\Tools\Workspace\GetWorkspaceTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Icon;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('TryPost')]
#[Version('1.0.0')]
#[Icon('images/trypost/icon.png', mimeType: 'image/png')]
#[Instructions('TryPost is a social media scheduling platform. Use this server to manage posts, the Asset Library, signatures, labels, social accounts, workspaces, and API keys.')]
class TryPostServer extends Server
{
    public int $defaultPaginationLength = 100;

    protected array $tools = [
        // Posts
        ListPostsTool::class,
        GetPostTool::class,
        CreatePostTool::class,
        UpdatePostTool::class,
        PublishPostTool::class,
        PreviewPostTool::class,
        DeletePostTool::class,
        AttachMediaFromUrlTool::class,
        RequestMediaUploadTool::class,
        AttachMediaFromUploadTool::class,
        GetPostMetricsTool::class,

        // Assets
        ListAssetsTool::class,
        GetAssetTool::class,
        AttachExistingAssetTool::class,

        // Platforms (read-only metadata)
        ListContentTypesTool::class,

        // Signatures
        ListSignaturesTool::class,
        CreateSignatureTool::class,
        UpdateSignatureTool::class,
        DeleteSignatureTool::class,

        // Labels
        ListLabelsTool::class,
        CreateLabelTool::class,
        UpdateLabelTool::class,
        DeleteLabelTool::class,

        // Social Accounts
        ListSocialAccountsTool::class,
        ListPinterestBoardsTool::class,
        ListDiscordChannelsTool::class,
        ToggleSocialAccountTool::class,

        // Workspace
        GetWorkspaceTool::class,

        // API Keys
        ListApiKeysTool::class,
        CreateApiKeyTool::class,
        DeleteApiKeyTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
