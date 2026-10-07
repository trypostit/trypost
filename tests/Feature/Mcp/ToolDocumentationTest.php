<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Idea\CreateIdeaTool;
use App\Mcp\Tools\Label\CreateLabelTool;
use App\Mcp\Tools\Label\UpdateLabelTool;
use App\Mcp\Tools\Post\AttachMediaFromUploadTool;
use App\Mcp\Tools\Post\AttachMediaFromUrlTool;
use App\Mcp\Tools\Post\CreatePostsTool;
use App\Mcp\Tools\Post\CreatePostTool;
use App\Mcp\Tools\Post\DeletePostTool;
use App\Mcp\Tools\Post\GetPostTool;
use App\Mcp\Tools\Post\PreviewPostTool;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Mcp\Tools\Post\SetPostRecurrenceTool;
use App\Mcp\Tools\Post\UpdatePostTool;
use App\Mcp\Tools\Repurpose\CreateRepurposeTool;
use App\Mcp\Tools\Repurpose\UpdateRepurposeTool;
use App\Mcp\Tools\Signature\CreateSignatureTool;
use App\Mcp\Tools\Signature\ListSignaturesTool;
use App\Mcp\Tools\SocialAccount\GeneratePostingScheduleTool;
use App\Mcp\Tools\SocialAccount\GetTikTokCreatorInfoTool;
use App\Mcp\Tools\SocialAccount\ListFreeSlotsTool;
use App\Mcp\Tools\SocialAccount\ReorderQueueTool;
use App\Mcp\Tools\SocialAccount\UpdatePostingScheduleTool;
use App\Mcp\Tools\Webhook\CreateWebhookTool;
use App\Support\PostPlatformMetaRules;
use Laravel\Mcp\Server\Attributes\Instructions;

function mcpToolText(string $tool): string
{
    return json_encode(app($tool)->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function mcpServerInstructions(): string
{
    return (new ReflectionClass(TryPostServer::class))->getAttributes(Instructions::class)[0]->newInstance()->value;
}

dataset('post writing tools', [
    'create-post' => [CreatePostTool::class],
    'create-posts' => [CreatePostsTool::class],
    'update-post' => [UpdatePostTool::class],
]);

test('post writing tools explain how to publish a thread', function (string $tool) {
    expect(mcpToolText($tool))->toContain('thread_replies')->toContain('X, Bluesky or Mastodon');
})->with('post writing tools');

test('post writing tools explain that content is html', function (string $tool) {
    expect(mcpToolText($tool))->toContain('<strong>')->toContain('&lt;');
})->with('post writing tools');

test('tools that schedule or publish list the required meta', function (string $tool) {
    expect(mcpToolText($tool))->toContain('privacy_level')->toContain('board_id')->toContain('channel_id')->toContain('required_meta');
})->with([
    'create-post' => [CreatePostTool::class],
    'create-posts' => [CreatePostsTool::class],
    'update-post' => [UpdatePostTool::class],
    'publish-post' => [PublishPostTool::class],
]);

test('tools that change a post list the statuses that block it', function (string $tool) {
    expect(mcpToolText($tool))->toContain('partially_published')->toContain('failed');
})->with([
    'update-post' => [UpdatePostTool::class],
    'delete-post' => [DeletePostTool::class],
]);

test('scheduled_at says it is utc and where the calendar ends', function (string $tool) {
    expect(mcpToolText($tool))->toContain('UTC')->toContain('2038-01-19');
})->with([
    'create-post' => [CreatePostTool::class],
    'create-posts' => [CreatePostsTool::class],
    'update-post' => [UpdatePostTool::class],
    'publish-post' => [PublishPostTool::class],
]);

test('get-post says its times are utc', function () {
    expect(mcpToolText(GetPostTool::class))->toContain('UTC');
});

test('delete-post says the post stays on the network', function () {
    expect(mcpToolText(DeletePostTool::class))->toContain('never removed from the network');
});

test('the url attach tool lists the download limits', function () {
    expect(mcpToolText(AttachMediaFromUrlTool::class))->toContain('20 seconds')->toContain('HEIC');
});

test('the upload attach tool names tools by their mcp name', function () {
    expect(mcpToolText(AttachMediaFromUploadTool::class))->not->toContain('RequestMediaUploadTool')->toContain('request-media-upload-tool');
});

test('preview-post does not promise truncation', function () {
    expect(mcpToolText(PreviewPostTool::class))->not->toContain('length truncation');
});

test('the meta documentation describes stories and single video feed posts', function () {
    expect(PostPlatformMetaRules::documentation())
        ->not->toContain('Instagram stories: no settings')
        ->toContain('publishes as a Reel');
});

test('the server instructions mention threads, the queue and approvals', function () {
    expect(mcpServerInstructions())->toContain('thread')->toContain('queue')->toContain('list-content-types-tool');
});

test('the posting schedule tools state their limits', function () {
    expect(mcpToolText(UpdatePostingScheduleTool::class))->toContain('at most 4')->toContain('1 to 28')
        ->and(mcpToolText(GeneratePostingScheduleTool::class))->toContain('1 to 28');
});

test('the queue tools state how many items they take', function () {
    expect(mcpToolText(ListFreeSlotsTool::class))->toContain('30')
        ->and(mcpToolText(ReorderQueueTool::class))->toContain('500');
});

test('the recurrence tool states its last possible occurrence', function () {
    expect(mcpToolText(SetPostRecurrenceTool::class))->toContain('2037-12-31');
});

test('tiktok creator info says the video duration is not checked for the agent', function () {
    expect(mcpToolText(GetTikTokCreatorInfoTool::class))->toContain('not checked');
});

test('signature tools say a signature is not added automatically', function (string $tool) {
    expect(mcpToolText($tool))->toContain('not added automatically');
})->with([
    'create-signature' => [CreateSignatureTool::class],
    'list-signatures' => [ListSignaturesTool::class],
]);

test('label tools give the color format', function (string $tool) {
    expect(mcpToolText($tool))->toContain('#RRGGBB');
})->with([
    'create-label' => [CreateLabelTool::class],
    'update-label' => [UpdateLabelTool::class],
]);

test('the idea tool states its limits', function () {
    expect(mcpToolText(CreateIdeaTool::class))->toContain('10000');
});

test('the webhook tool explains the signature and the pause', function () {
    expect(mcpToolText(CreateWebhookTool::class))->toContain('255')->toContain('X-Webhook-Signature')->toContain('5 failed');
});

test('repurpose tools give real schemas for formats, modes and destinations', function (string $tool) {
    $properties = data_get(app($tool)->toArray(), 'inputSchema.properties');

    expect(data_get($properties, 'source_format.enum'))->toBe(['reel', 'video', 'story'])
        ->and(data_get($properties, 'publish_mode.enum'))->toBe(['publish', 'draft'])
        ->and(array_keys(data_get($properties, 'destinations.items.properties')))->toBe(['social_account_id', 'content_type', 'meta']);
})->with([
    'create-repurpose' => [CreateRepurposeTool::class],
    'update-repurpose' => [UpdateRepurposeTool::class],
]);
