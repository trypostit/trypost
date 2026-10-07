<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\Analytics\GetAnalyticsPublicationTool;
use App\Mcp\Tools\Analytics\GetAnalyticsReportTool;
use App\Mcp\Tools\Analytics\GetChannelInsightsTool;
use App\Mcp\Tools\Analytics\ListChannelPublicationsTool;
use App\Mcp\Tools\ApiKey\CreateApiKeyTool;
use App\Mcp\Tools\ApiKey\DeleteApiKeyTool;
use App\Mcp\Tools\ApiKey\ListApiKeysTool;
use App\Mcp\Tools\Idea\CreateIdeaStageTool;
use App\Mcp\Tools\Idea\CreateIdeaTool;
use App\Mcp\Tools\Idea\DeleteIdeaStageTool;
use App\Mcp\Tools\Idea\DeleteIdeasTool;
use App\Mcp\Tools\Idea\DuplicateIdeaTool;
use App\Mcp\Tools\Idea\GetIdeaTool;
use App\Mcp\Tools\Idea\ListIdeaStagesTool;
use App\Mcp\Tools\Idea\ListIdeasTool;
use App\Mcp\Tools\Idea\MoveIdeasTool;
use App\Mcp\Tools\Idea\ReorderIdeaStagesTool;
use App\Mcp\Tools\Idea\UpdateIdeaStageTool;
use App\Mcp\Tools\Idea\UpdateIdeaTool;
use App\Mcp\Tools\Label\CreateLabelTool;
use App\Mcp\Tools\Label\DeleteLabelTool;
use App\Mcp\Tools\Label\ListLabelsTool;
use App\Mcp\Tools\Label\UpdateLabelTool;
use App\Mcp\Tools\Platform\ListContentTypesTool;
use App\Mcp\Tools\Post\ApprovePostTool;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\ClearPostRecurrenceTool;
use App\Mcp\Tools\Post\CreatePostNoteTool;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\DeletePostNoteTool;
use App\Mcp\Tools\Post\DeletePostTool;
use App\Mcp\Tools\Post\GetPostMetricsTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\ListPostNotesTool;
use App\Mcp\Tools\Post\ListPostsTool;
use App\Mcp\Tools\Post\PreviewPostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\RejectPostTool;
use App\Mcp\Tools\Post\RequestMediaUploadTool;
use App\Mcp\Tools\Post\SetPostRecurrenceTool;
use App\Mcp\Tools\Post\UpdatePostNoteTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Mcp\Tools\Repurpose\ActivateRepurposeTool;
use App\Mcp\Tools\Repurpose\CreateRepurposeTool;
use App\Mcp\Tools\Repurpose\DeleteRepurposeTool;
use App\Mcp\Tools\Repurpose\DisableRepurposeTool;
use App\Mcp\Tools\Repurpose\GetRepurposeTool;
use App\Mcp\Tools\Repurpose\ListRepurposeItemsTool;
use App\Mcp\Tools\Repurpose\ListRepurposeSourceFormatsTool;
use App\Mcp\Tools\Repurpose\ListRepurposesTool;
use App\Mcp\Tools\Repurpose\PauseRepurposeTool;
use App\Mcp\Tools\Repurpose\ResumeRepurposeTool;
use App\Mcp\Tools\Repurpose\UpdateRepurposeTool;
use App\Mcp\Tools\Signature\CreateSignatureTool;
use App\Mcp\Tools\Signature\DeleteSignatureTool;
use App\Mcp\Tools\Signature\ListSignaturesTool;
use App\Mcp\Tools\Signature\UpdateSignatureTool;
use App\Mcp\Tools\SocialAccount\CopyPostingScheduleTool;
use App\Mcp\Tools\SocialAccount\CreatePinterestBoardTool;
use App\Mcp\Tools\SocialAccount\GeneratePostingScheduleTool;
use App\Mcp\Tools\SocialAccount\GetPostingScheduleTool;
use App\Mcp\Tools\SocialAccount\GetTikTokCreatorInfoTool;
use App\Mcp\Tools\SocialAccount\ListDiscordChannelsTool;
use App\Mcp\Tools\SocialAccount\ListFreeSlotsTool;
use App\Mcp\Tools\SocialAccount\ListPinterestBoardsTool;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Mcp\Tools\SocialAccount\MovePostToSlotTool;
use App\Mcp\Tools\SocialAccount\ReorderQueueTool;
use App\Mcp\Tools\SocialAccount\UpdatePostingScheduleTool;
use App\Mcp\Tools\Webhook\CreateWebhookTool;
use App\Mcp\Tools\Webhook\DeleteWebhookTool;
use App\Mcp\Tools\Webhook\GetWebhookTool;
use App\Mcp\Tools\Webhook\ListWebhookLogsTool;
use App\Mcp\Tools\Webhook\ListWebhooksTool;
use App\Mcp\Tools\Webhook\ReplayWebhookLogTool;
use App\Mcp\Tools\Webhook\RotateWebhookSecretTool;
use App\Mcp\Tools\Webhook\SendWebhookTestTool;
use App\Mcp\Tools\Webhook\UpdateWebhookTool;
use App\Mcp\Tools\Workspace\GetWorkspaceTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Icon;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('TryPost')]
#[Version('1.0.0')]
#[Icon('images/trypost/icon.png', mimeType: 'image/png')]
#[Instructions('TryPost is a social media scheduling platform. Use this server to manage posts (drafts, custom times, the channel queue and its free slots, recurrence, threads on X, Bluesky and Mastodon through meta.thread_replies, previews, notes and approvals), ideas, analytics, signatures, labels, social accounts and their posting times, workspaces, outgoing webhooks, repurposes (auto-replicating videos posted outside TryPost), and API keys. Call list-content-types-tool before choosing a content_type or attaching media: it lists the media rules, text and hashtag limits, thread support and the meta each network requires. Media is attached to a post by upload or URL; an upload is temporary, kept for 24 hours and single-use, and every post keeps its own copy of its files. Times are UTC. Members who need approval in a workspace can create and edit posts, but scheduling, queueing or publishing stores their post with status pending_approval until a member who publishes directly calls approve-post-tool or reject-post-tool; get-workspace-tool tells the caller which applies.')]
class TryPostServer extends Server
{
    public int $defaultPaginationLength = 100;

    protected array $tools = [
        ListPostsTool::class,
        GetPostTool::class,
        CreatePostTool::class,
        CreatePostsTool::class,
        UpdatePostTool::class,
        PublishPostTool::class,
        ApprovePostTool::class,
        RejectPostTool::class,
        PreviewPostTool::class,
        DeletePostTool::class,
        AttachMediaFromUrlTool::class,
        RequestMediaUploadTool::class,
        AttachMediaFromUploadTool::class,
        GetPostMetricsTool::class,
        ListPostNotesTool::class,
        CreatePostNoteTool::class,
        UpdatePostNoteTool::class,
        DeletePostNoteTool::class,

        GetAnalyticsReportTool::class,
        GetAnalyticsPublicationTool::class,
        GetChannelInsightsTool::class,
        ListChannelPublicationsTool::class,

        ListContentTypesTool::class,

        ListSignaturesTool::class,
        CreateSignatureTool::class,
        UpdateSignatureTool::class,
        DeleteSignatureTool::class,

        ListIdeasTool::class,
        GetIdeaTool::class,
        CreateIdeaTool::class,
        UpdateIdeaTool::class,
        DeleteIdeasTool::class,
        DuplicateIdeaTool::class,
        MoveIdeasTool::class,

        ListIdeaStagesTool::class,
        CreateIdeaStageTool::class,
        UpdateIdeaStageTool::class,
        DeleteIdeaStageTool::class,
        ReorderIdeaStagesTool::class,

        ListLabelsTool::class,
        CreateLabelTool::class,
        UpdateLabelTool::class,
        DeleteLabelTool::class,

        ListSocialAccountsTool::class,
        GetPostingScheduleTool::class,
        UpdatePostingScheduleTool::class,
        GeneratePostingScheduleTool::class,
        CopyPostingScheduleTool::class,
        ListFreeSlotsTool::class,
        ReorderQueueTool::class,
        MovePostToSlotTool::class,
        SetPostRecurrenceTool::class,
        ClearPostRecurrenceTool::class,
        ListPinterestBoardsTool::class,
        CreatePinterestBoardTool::class,
        ListDiscordChannelsTool::class,
        GetTikTokCreatorInfoTool::class,
        ListRepurposesTool::class,
        CreateRepurposeTool::class,
        GetRepurposeTool::class,
        UpdateRepurposeTool::class,
        ActivateRepurposeTool::class,
        PauseRepurposeTool::class,
        ResumeRepurposeTool::class,
        DisableRepurposeTool::class,
        ListRepurposeItemsTool::class,
        ListRepurposeSourceFormatsTool::class,
        DeleteRepurposeTool::class,

        ListWebhooksTool::class,
        GetWebhookTool::class,
        CreateWebhookTool::class,
        UpdateWebhookTool::class,
        DeleteWebhookTool::class,
        SendWebhookTestTool::class,
        RotateWebhookSecretTool::class,
        ListWebhookLogsTool::class,
        ReplayWebhookLogTool::class,

        GetWorkspaceTool::class,

        ListApiKeysTool::class,
        CreateApiKeyTool::class,
        DeleteApiKeyTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
