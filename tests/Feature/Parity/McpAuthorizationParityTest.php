<?php

declare(strict_types=1);

use App\Actions\ApiKey\CreateApiKey;
use App\Actions\Post\CreatePosts;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Servers\TryPostServer;
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
use App\Models\AnalyticsPublication;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use App\Services\Http\SafeHttpFetcher;
use App\Services\WebhookService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

function mcpAuthParityActor(Workspace $workspace, User $owner, string $role): User
{
    return match ($role) {
        'owner' => $owner,
        'outsider' => workspaceOutsider($workspace),
        default => workspaceMember($workspace, $role),
    };
}

/**
 * @return array<string, mixed>
 */
function mcpAuthParityFixture(Workspace $workspace, User $owner, ?User $actor = null): array
{
    $linkedin = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
    ]);
    $post = Post::factory()->forAccount($linkedin, ContentType::LinkedInPost)->draft()->create(['user_id' => $owner->id, 'content' => 'Draft']);
    $pending = CreatePosts::execute($workspace, workspaceMember($workspace, 'approval'), [
        'status' => 'scheduled',
        'scheduled_at' => now()->addDays(2)->toIso8601String(),
        'content' => 'Needs a review',
        'media' => [],
        'destinations' => [[
            'social_account_id' => $linkedin->id,
            'content_type' => ContentType::LinkedInPost->value,
            'meta' => [],
        ]],
    ])->sole();
    $queueChannel = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_schedule' => collect(range(0, 6))->map(fn (int $day) => ['day' => $day, 'enabled' => true, 'times' => ['09:00']])->all(),
    ]);
    $queueSlots = $queueChannel->posting_schedule->nextSlots(now()->addMinute(), 'UTC', 3);
    $queued = [];

    foreach (['queue', 'custom'] as $mode) {
        $queued[$mode] = Post::factory()->forAccount($queueChannel, ContentType::LinkedInPost)->create([
            'user_id' => $owner->id,
            'status' => Status::Scheduled,
            'content' => 'Queued parity post',
            'schedule_mode' => $mode,
            'scheduled_at' => $mode === 'queue' ? $queueSlots[0] : now()->addDays(9)->setTime(10, 7),
        ]);
    }
    $instagram = SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]);
    $webhook = Webhook::factory()->create(['workspace_id' => $workspace->id]);
    $uploadToken = (string) Str::uuid();
    Media::factory()->temporaryUpload($workspace)->create(['upload_token' => $uploadToken]);

    return [
        'linkedin' => $linkedin,
        'queueChannel' => $queueChannel,
        'queuedPost' => $queued['queue'],
        'customPost' => $queued['custom'],
        'freeSlot' => $queueSlots[1]->toIso8601ZuluString(),
        'post' => $post,
        'note' => PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $owner->id]),
        'tiktok' => SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id, 'platform_user_id' => (string) Str::uuid()]),
        'pending' => $pending,
        'instagram' => SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id]),
        'repurpose' => Repurpose::factory()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'source_social_account_id' => $instagram->id]),
        'repurposeReady' => Repurpose::factory()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'source_social_account_id' => SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id])->id,
            'destinations' => [['social_account_id' => $linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []]],
        ]),
        'repurposeActive' => Repurpose::factory()->active()->create(['workspace_id' => $workspace->id, 'user_id' => $owner->id, 'source_social_account_id' => SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id])->id]),
        'repurposePaused' => Repurpose::factory()->paused()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'source_social_account_id' => SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id])->id,
            'destinations' => [['social_account_id' => $linkedin->id, 'content_type' => ContentType::LinkedInPost->value, 'meta' => []]],
        ]),
        'apiKey' => CreateApiKey::execute($actor ?? $owner, $workspace, ['name' => 'Existing'])['token'],
        'publication' => AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id]),
        'webhook' => $webhook,
        'log' => WebhookLog::factory()->create(['webhook_id' => $webhook->id]),
        'ideaStage' => IdeaStage::factory()->create(['workspace_id' => $workspace->id]),
        'idea' => Idea::factory()->create(['workspace_id' => $workspace->id, 'idea_stage_id' => IdeaStage::factory()->create(['workspace_id' => $workspace->id])->id]),
        'ideaStageIds' => $workspace->ideaStages()->pluck('id')->all(),
        'label' => WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]),
        'signature' => WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id]),
        'discord' => SocialAccount::factory()->discord()->create(['workspace_id' => $workspace->id, 'platform_user_id' => (string) Str::uuid()]),
        'pinterest' => SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id, 'platform_user_id' => (string) Str::uuid()]),
        'uploadToken' => $uploadToken,
        'publicationId' => (string) Str::uuid(),
        'missingId' => (string) Str::uuid(),
    ];
}

/**
 * @return array<string, mixed>
 */
function mcpAuthParityDestination(array $fixture): array
{
    return ['social_account_id' => $fixture['linkedin']->id, 'content_type' => ContentType::LinkedInPost->value];
}

/**
 * Every MCP tool with the web route that does the same thing, the roles the web refuses,
 * and the tool arguments.
 *
 * @return array<string, array{0: class-string, 1: list<string>, 2: Closure, 3: Closure}>
 */
function mcpAuthParityTools(): array
{
    $everyMember = ['outsider'];
    $admins = ['member', 'approval', 'outsider'];
    $publishers = ['approval', 'outsider'];
    $label = ['name' => 'Launch', 'color' => '#112233'];
    $signature = ['name' => 'Sign-off', 'content' => 'Cheers'];
    $webhook = ['endpoint' => 'https://example.com/hook', 'events' => ['post.published']];

    return [
        'get-analytics-report-tool' => [GetAnalyticsReportTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.insights')), fn (array $f) => []],
        'get-analytics-publication-tool' => [GetAnalyticsPublicationTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.insights.publications.details', $f['publication'])), fn (array $f) => ['publication_id' => $f['publication']->id]],
        'create-api-key-tool' => [CreateApiKeyTool::class, $admins, fn (TestCase $t, array $f) => $t->post(route('app.api-keys.store'), ['name' => 'Integration']), fn (array $f) => ['name' => 'Integration']],
        'delete-api-key-tool' => [DeleteApiKeyTool::class, $admins, fn (TestCase $t, array $f) => $t->delete(route('app.api-keys.destroy', $f['apiKey'])), fn (array $f) => ['api_key_id' => $f['apiKey']->id]],
        'list-api-keys-tool' => [ListApiKeysTool::class, $admins, fn (TestCase $t, array $f) => $t->get(route('app.api-keys.index')), fn (array $f) => []],
        'list-ideas-tool' => [ListIdeasTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.create.ideas.index')), fn (array $f) => []],
        'get-idea-tool' => [GetIdeaTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.create.ideas.show', $f['idea'])), fn (array $f) => ['idea_id' => $f['idea']->id]],
        'create-idea-tool' => [CreateIdeaTool::class, $everyMember, fn (TestCase $t, array $f) => $t->post(route('app.create.ideas.store'), ['title' => 'Idea']), fn (array $f) => ['title' => 'Idea']],
        'update-idea-tool' => [UpdateIdeaTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.create.ideas.update', $f['idea']), ['title' => 'Idea']), fn (array $f) => ['idea_id' => $f['idea']->id, 'title' => 'Idea']],
        'delete-ideas-tool' => [DeleteIdeasTool::class, $everyMember, fn (TestCase $t, array $f) => $t->delete(route('app.create.ideas.bulk-destroy'), ['idea_ids' => [$f['idea']->id]]), fn (array $f) => ['idea_ids' => [$f['idea']->id]]],
        'duplicate-idea-tool' => [DuplicateIdeaTool::class, $everyMember, fn (TestCase $t, array $f) => $t->post(route('app.create.ideas.duplicate', $f['idea'])), fn (array $f) => ['idea_id' => $f['idea']->id]],
        'move-ideas-tool' => [MoveIdeasTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.create.ideas.move', $f['idea']), ['idea_stage_id' => $f['idea']->idea_stage_id, 'idea_ids' => [$f['idea']->id]]), fn (array $f) => ['idea_id' => $f['idea']->id, 'idea_stage_id' => $f['idea']->idea_stage_id, 'idea_ids' => [$f['idea']->id]]],
        'list-idea-stages-tool' => [ListIdeaStagesTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.create.ideas.index')), fn (array $f) => []],
        'create-idea-stage-tool' => [CreateIdeaStageTool::class, $everyMember, fn (TestCase $t, array $f) => $t->post(route('app.create.idea-stages.store'), ['name' => 'Review']), fn (array $f) => ['name' => 'Review']],
        'update-idea-stage-tool' => [UpdateIdeaStageTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.create.idea-stages.update', $f['ideaStage']), ['name' => 'Review']), fn (array $f) => ['idea_stage_id' => $f['ideaStage']->id, 'name' => 'Review']],
        'delete-idea-stage-tool' => [DeleteIdeaStageTool::class, $everyMember, fn (TestCase $t, array $f) => $t->delete(route('app.create.idea-stages.destroy', $f['ideaStage'])), fn (array $f) => ['idea_stage_id' => $f['ideaStage']->id]],
        'reorder-idea-stages-tool' => [ReorderIdeaStagesTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.create.idea-stages.reorder'), ['stage_ids' => $f['ideaStageIds']]), fn (array $f) => ['stage_ids' => $f['ideaStageIds']]],
        'create-label-tool' => [CreateLabelTool::class, $everyMember, fn (TestCase $t, array $f) => $t->post(route('app.labels.store'), $label), fn (array $f) => $label],
        'delete-label-tool' => [DeleteLabelTool::class, $everyMember, fn (TestCase $t, array $f) => $t->delete(route('app.labels.destroy', $f['label'])), fn (array $f) => ['label_id' => $f['label']->id]],
        'list-labels-tool' => [ListLabelsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.labels.index')), fn (array $f) => []],
        'update-label-tool' => [UpdateLabelTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.labels.update', $f['label']), $label), fn (array $f) => ['label_id' => $f['label']->id, ...$label]],
        'list-content-types-tool' => [ListContentTypesTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.posts.composer.live')), fn (array $f) => []],
        'approve-post-tool' => [ApprovePostTool::class, $publishers, fn (TestCase $t, array $f) => $t->put(route('app.posts.approve', $f['pending'])), fn (array $f) => ['post_id' => $f['pending']->id]],
        'reject-post-tool' => [RejectPostTool::class, $publishers, fn (TestCase $t, array $f) => $t->put(route('app.posts.reject', $f['pending'])), fn (array $f) => ['post_id' => $f['pending']->id]],
        'attach-media-from-upload-tool' => [AttachMediaFromUploadTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.posts.update', $f['post']), ['status' => 'draft', 'content' => 'Edited', 'media' => []]), fn (array $f) => ['post_id' => $f['post']->id, 'upload_token' => $f['uploadToken']]],
        'attach-media-from-url-tool' => [AttachMediaFromUrlTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.posts.update', $f['post']), ['status' => 'draft', 'content' => 'Edited', 'media' => []]), fn (array $f) => ['post_id' => $f['post']->id, 'urls' => [['url' => 'https://example.com/photo.jpg']]]],
        'create-posts-tool' => [CreatePostsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->post(route('app.posts.store'), ['status' => 'draft', 'content' => 'Hello', 'media' => [], 'destinations' => [mcpAuthParityDestination($f)]]), fn (array $f) => ['status' => 'draft', 'content' => 'Hello', 'destinations' => [mcpAuthParityDestination($f)]]],
        'create-post-tool' => [CreatePostTool::class, $everyMember, fn (TestCase $t, array $f) => $t->post(route('app.posts.store'), ['status' => 'draft', 'content' => 'Hello', 'media' => [], 'destinations' => [mcpAuthParityDestination($f)]]), fn (array $f) => ['content' => 'Hello', ...mcpAuthParityDestination($f)]],
        'delete-post-tool' => [DeletePostTool::class, $publishers, fn (TestCase $t, array $f) => $t->delete(route('app.posts.destroy', $f['post'])), fn (array $f) => ['post_id' => $f['post']->id]],
        'get-post-metrics-tool' => [GetPostMetricsTool::class, [], fn (TestCase $t, array $f) => $t->get(route('app.posts.edit', $f['post'])), fn (array $f) => ['post_id' => $f['post']->id]],
        'get-post-tool' => [GetPostTool::class, [], fn (TestCase $t, array $f) => $t->get(route('app.posts.edit', $f['post'])), fn (array $f) => ['post_id' => $f['post']->id]],
        'list-post-notes-tool' => [ListPostNotesTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.posts.notes.index', $f['post'])), fn (array $f) => ['post_id' => $f['post']->id]],
        'create-post-note-tool' => [CreatePostNoteTool::class, $everyMember, fn (TestCase $t, array $f) => $t->postJson(route('app.posts.notes.store', $f['post']), ['body' => 'Looks good']), fn (array $f) => ['post_id' => $f['post']->id, 'body' => 'Looks good']],
        'update-post-note-tool' => [UpdatePostNoteTool::class, ['admin', 'member', 'approval', 'outsider'], fn (TestCase $t, array $f) => $t->putJson(route('app.posts.notes.update', [$f['post'], $f['note']]), ['body' => 'Edited']), fn (array $f) => ['post_id' => $f['post']->id, 'note_id' => $f['note']->id, 'body' => 'Edited']],
        'delete-post-note-tool' => [DeletePostNoteTool::class, ['admin', 'member', 'approval', 'outsider'], fn (TestCase $t, array $f) => $t->deleteJson(route('app.posts.notes.destroy', [$f['post'], $f['note']])), fn (array $f) => ['post_id' => $f['post']->id, 'note_id' => $f['note']->id]],
        'get-tiktok-creator-info-tool' => [GetTikTokCreatorInfoTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.posts.composer.account', $f['tiktok'])), fn (array $f) => ['account_id' => $f['tiktok']->id]],
        'list-posts-tool' => [ListPostsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.posts.index')), fn (array $f) => []],
        'preview-post-tool' => [PreviewPostTool::class, [], fn (TestCase $t, array $f) => $t->get(route('app.posts.edit', $f['post'])), fn (array $f) => ['post_id' => $f['post']->id]],
        'publish-post-tool' => [PublishPostTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.posts.update', $f['post']), ['status' => 'scheduled', 'scheduled_at' => now()->addDays(2)->toIso8601String(), 'content' => 'Draft', 'media' => [], 'content_type' => ContentType::LinkedInPost->value]), fn (array $f) => ['post_id' => $f['post']->id, 'scheduled_at' => now()->addDays(2)->toIso8601String()]],
        'request-media-upload-tool' => [RequestMediaUploadTool::class, $everyMember, fn (TestCase $t, array $f) => $t->postJson(route('app.media.store-chunked')), fn (array $f) => []],
        'update-post-tool' => [UpdatePostTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.posts.update', $f['post']), ['status' => 'draft', 'content' => 'Edited', 'media' => []]), fn (array $f) => ['post_id' => $f['post']->id, 'content' => 'Edited']],
        'activate-repurpose-tool' => [ActivateRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->post(route('app.repurposes.activate', $f['repurposeReady'])), fn (array $f) => ['repurpose_id' => $f['repurposeReady']->id]],
        'create-repurpose-tool' => [CreateRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->post(route('app.repurposes.store'), ['source_social_account_id' => $f['instagram']->id, 'source_format' => 'reel']), fn (array $f) => ['source_social_account_id' => $f['instagram']->id, 'source_format' => 'reel']],
        'delete-repurpose-tool' => [DeleteRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->delete(route('app.repurposes.destroy', $f['repurpose'])), fn (array $f) => ['repurpose_id' => $f['repurpose']->id]],
        'disable-repurpose-tool' => [DisableRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->post(route('app.repurposes.disable', $f['repurposeActive'])), fn (array $f) => ['repurpose_id' => $f['repurposeActive']->id]],
        'get-repurpose-tool' => [GetRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->get(route('app.repurposes.show', $f['repurpose'])), fn (array $f) => ['repurpose_id' => $f['repurpose']->id]],
        'list-repurpose-items-tool' => [ListRepurposeItemsTool::class, $publishers, fn (TestCase $t, array $f) => $t->get(route('app.repurposes.show', $f['repurpose'])), fn (array $f) => ['repurpose_id' => $f['repurpose']->id]],
        'list-repurpose-source-formats-tool' => [ListRepurposeSourceFormatsTool::class, $publishers, fn (TestCase $t, array $f) => $t->get(route('app.repurposes.index')), fn (array $f) => []],
        'list-repurposes-tool' => [ListRepurposesTool::class, $publishers, fn (TestCase $t, array $f) => $t->get(route('app.repurposes.index')), fn (array $f) => []],
        'pause-repurpose-tool' => [PauseRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->post(route('app.repurposes.pause', $f['repurposeActive'])), fn (array $f) => ['repurpose_id' => $f['repurposeActive']->id]],
        'resume-repurpose-tool' => [ResumeRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->post(route('app.repurposes.resume', $f['repurposePaused'])), fn (array $f) => ['repurpose_id' => $f['repurposePaused']->id]],
        'update-repurpose-tool' => [UpdateRepurposeTool::class, $publishers, fn (TestCase $t, array $f) => $t->put(route('app.repurposes.update', $f['repurpose']), ['source_social_account_id' => $f['instagram']->id, 'source_format' => 'reel']), fn (array $f) => ['repurpose_id' => $f['repurpose']->id, 'source_social_account_id' => $f['instagram']->id, 'source_format' => 'reel']],
        'create-signature-tool' => [CreateSignatureTool::class, $everyMember, fn (TestCase $t, array $f) => $t->post(route('app.signatures.store'), $signature), fn (array $f) => $signature],
        'delete-signature-tool' => [DeleteSignatureTool::class, $everyMember, fn (TestCase $t, array $f) => $t->delete(route('app.signatures.destroy', $f['signature'])), fn (array $f) => ['signature_id' => $f['signature']->id]],
        'list-signatures-tool' => [ListSignaturesTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.signatures.index')), fn (array $f) => []],
        'update-signature-tool' => [UpdateSignatureTool::class, $everyMember, fn (TestCase $t, array $f) => $t->put(route('app.signatures.update', $f['signature']), $signature), fn (array $f) => ['signature_id' => $f['signature']->id, ...$signature]],
        'list-discord-channels-tool' => [ListDiscordChannelsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.discord.channels', $f['discord'])), fn (array $f) => ['account_id' => $f['discord']->id]],
        'create-pinterest-board-tool' => [CreatePinterestBoardTool::class, $everyMember, fn (TestCase $t, array $f) => $t->postJson(route('app.pinterest.boards.store', $f['pinterest']), ['name' => 'Ideas']), fn (array $f) => ['account_id' => $f['pinterest']->id, 'name' => 'Ideas']],
        'list-pinterest-boards-tool' => [ListPinterestBoardsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.pinterest.boards.index', $f['pinterest'])), fn (array $f) => ['account_id' => $f['pinterest']->id]],
        'get-posting-schedule-tool' => [GetPostingScheduleTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.posts.composer.live')), fn (array $f) => ['account_id' => $f['linkedin']->id]],
        'update-posting-schedule-tool' => [UpdatePostingScheduleTool::class, $admins, fn (TestCase $t, array $f) => $t->putJson(route('app.channels.posting-schedule.update', $f['linkedin']), ['timezone' => 'UTC', 'posting_goal' => 2, 'posting_schedule' => null]), fn (array $f) => ['account_id' => $f['linkedin']->id, 'timezone' => 'UTC', 'posting_goal' => 2, 'posting_schedule' => null]],
        'generate-posting-schedule-tool' => [GeneratePostingScheduleTool::class, $admins, fn (TestCase $t, array $f) => $t->postJson(route('app.channels.posting-schedule.generate', $f['linkedin']), ['mode' => 'goal', 'goal' => 3]), fn (array $f) => ['account_id' => $f['linkedin']->id, 'mode' => 'goal', 'goal' => 3]],
        'copy-posting-schedule-tool' => [CopyPostingScheduleTool::class, $admins, fn (TestCase $t, array $f) => $t->postJson(route('app.channels.posting-schedule.copy', $f['linkedin']), ['from' => $f['queueChannel']->id]), fn (array $f) => ['account_id' => $f['linkedin']->id, 'from' => $f['queueChannel']->id]],
        'list-free-slots-tool' => [ListFreeSlotsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.posts.composer.live')), fn (array $f) => ['account_id' => $f['linkedin']->id]],
        'reorder-queue-tool' => [ReorderQueueTool::class, $publishers, fn (TestCase $t, array $f) => $t->putJson(route('app.channels.queue.order', $f['queueChannel']), ['post_ids' => [$f['queuedPost']->id]]), fn (array $f) => ['account_id' => $f['queueChannel']->id, 'post_ids' => [$f['queuedPost']->id]]],
        'move-post-to-slot-tool' => [MovePostToSlotTool::class, $publishers, fn (TestCase $t, array $f) => $t->putJson(route('app.channels.queue.slot', $f['queueChannel']), ['post_id' => $f['customPost']->id, 'slot_at' => $f['freeSlot']]), fn (array $f) => ['account_id' => $f['queueChannel']->id, 'post_id' => $f['customPost']->id, 'slot_at' => $f['freeSlot']]],
        'set-post-recurrence-tool' => [SetPostRecurrenceTool::class, $publishers, fn (TestCase $t, array $f) => $t->patchJson(route('app.posts.recurrence.update', $f['customPost']), ['interval' => 1, 'frequency' => 'day', 'times' => 2]), fn (array $f) => ['post_id' => $f['customPost']->id, 'interval' => 1, 'frequency' => 'day', 'times' => 2]],
        'get-channel-insights-tool' => [GetChannelInsightsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.channels.insights', $f['instagram'])), fn (array $f) => ['account_id' => $f['instagram']->id]],
        'list-channel-publications-tool' => [ListChannelPublicationsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.channels.insights', $f['instagram'])), fn (array $f) => ['account_id' => $f['instagram']->id]],
        'clear-post-recurrence-tool' => [ClearPostRecurrenceTool::class, $everyMember, fn (TestCase $t, array $f) => $t->deleteJson(route('app.posts.recurrence.destroy', $f['customPost'])), fn (array $f) => ['post_id' => $f['customPost']->id]],
        'list-social-accounts-tool' => [ListSocialAccountsTool::class, $everyMember, fn (TestCase $t, array $f) => $t->getJson(route('app.posts.composer.live')), fn (array $f) => []],
        'create-webhook-tool' => [CreateWebhookTool::class, $admins, fn (TestCase $t, array $f) => $t->post(route('app.webhooks.store'), $webhook), fn (array $f) => $webhook],
        'delete-webhook-tool' => [DeleteWebhookTool::class, $admins, fn (TestCase $t, array $f) => $t->delete(route('app.webhooks.destroy', $f['webhook'])), fn (array $f) => ['webhook_id' => $f['webhook']->id]],
        'get-webhook-tool' => [GetWebhookTool::class, $admins, fn (TestCase $t, array $f) => $t->get(route('app.webhooks.show', $f['webhook'])), fn (array $f) => ['webhook_id' => $f['webhook']->id]],
        'list-webhook-logs-tool' => [ListWebhookLogsTool::class, $admins, fn (TestCase $t, array $f) => $t->get(route('app.webhooks.show', $f['webhook'])), fn (array $f) => ['webhook_id' => $f['webhook']->id]],
        'list-webhooks-tool' => [ListWebhooksTool::class, $admins, fn (TestCase $t, array $f) => $t->get(route('app.webhooks.index')), fn (array $f) => []],
        'replay-webhook-log-tool' => [ReplayWebhookLogTool::class, $admins, fn (TestCase $t, array $f) => $t->post(route('app.webhooks.replay', [$f['webhook'], $f['log']])), fn (array $f) => ['webhook_id' => $f['webhook']->id, 'log_id' => $f['log']->id]],
        'rotate-webhook-secret-tool' => [RotateWebhookSecretTool::class, $admins, fn (TestCase $t, array $f) => $t->post(route('app.webhooks.rotate-secret', $f['webhook'])), fn (array $f) => ['webhook_id' => $f['webhook']->id]],
        'send-webhook-test-tool' => [SendWebhookTestTool::class, $admins, fn (TestCase $t, array $f) => $t->post(route('app.webhooks.send-test', $f['webhook'])), fn (array $f) => ['webhook_id' => $f['webhook']->id]],
        'update-webhook-tool' => [UpdateWebhookTool::class, $admins, fn (TestCase $t, array $f) => $t->put(route('app.webhooks.update', $f['webhook']), $webhook), fn (array $f) => ['webhook_id' => $f['webhook']->id, ...$webhook]],
        'get-workspace-tool' => [GetWorkspaceTool::class, $everyMember, fn (TestCase $t, array $f) => $t->get(route('app.calendar')), fn (array $f) => []],
    ];
}

beforeEach(function () {
    Queue::fake();
    Http::fake();
    $webhookService = Mockery::mock(WebhookService::class, [app(SafeHttpFetcher::class)])->makePartial();
    $webhookService->shouldReceive('assertEndpointAllowed')->andReturnNull();
    $webhookService->shouldReceive('ping')->andReturnNull();
    app()->instance(WebhookService::class, $webhookService);
    ['user' => $this->owner, 'workspace' => $this->workspace] = parityContext();
    $this->unauthorized = (new AuthorizationException)->getMessage();
});

test('every mcp tool allows and refuses each role exactly like its web route', function (string $tool, array $refused, Closure $web, Closure $arguments, string $role) {
    $actor = mcpAuthParityActor($this->workspace, $this->owner, $role);

    /** @var TestResponse $webResponse */
    $webResponse = $web($this->actingAs($actor), mcpAuthParityFixture($this->workspace, $this->owner));
    $webRefused = $webResponse->status() === Response::HTTP_FORBIDDEN;

    expect($webRefused)->toBe(in_array($role, $refused, true));

    auth()->forgetGuards();
    $mcp = TryPostServer::actingAs($actor->fresh())->tool($tool, $arguments(mcpAuthParityFixture($this->workspace, $this->owner, $actor)));

    if ($webRefused) {
        $mcp->assertHasErrors([$this->unauthorized]);
    } else {
        $mcp->assertHasNoErrors()->assertDontSee($this->unauthorized);
    }
})->with(mcpAuthParityTools())->with(['owner', 'admin', 'member', 'approval', 'outsider']);

test('the mcp tool list in the parity dataset covers every registered tool', function () {
    $registered = collect((new ReflectionProperty(TryPostServer::class, 'tools'))->getDefaultValue())->sort()->values()->all();
    $covered = collect(mcpAuthParityTools())->pluck(0)->sort()->values()->all();

    expect($covered)->toBe($registered);
});

test('a member who needs approval ends pending when scheduling through create-post-tool and update-post-tool, like the web', function () {
    $requester = workspaceMember($this->workspace, 'approval');
    $fixture = mcpAuthParityFixture($this->workspace, $this->owner);
    $at = now()->addDays(2)->startOfHour()->toIso8601String();
    $webDraft = Post::factory()->forAccount($fixture['linkedin'], ContentType::LinkedInPost)->draft()->create(['user_id' => $requester->id, 'content' => 'Web draft']);
    $mcpDraft = Post::factory()->forAccount($fixture['linkedin'], ContentType::LinkedInPost)->draft()->create(['user_id' => $requester->id, 'content' => 'Mcp draft']);

    $this->actingAs($requester)->post(route('app.posts.store'), [
        'status' => 'scheduled',
        'scheduled_at' => $at,
        'content' => 'Created on the web',
        'media' => [],
        'destinations' => [mcpAuthParityDestination($fixture)],
    ])->assertRedirect();
    $this->actingAs($requester)->put(route('app.posts.update', $webDraft), [
        'status' => 'scheduled',
        'scheduled_at' => $at,
        'content' => 'Web draft',
        'media' => [],
        'content_type' => ContentType::LinkedInPost->value,
    ])->assertRedirect();

    auth()->forgetGuards();
    TryPostServer::actingAs($requester)->tool(CreatePostTool::class, [
        'content' => 'Created through mcp',
        'status' => 'scheduled',
        'scheduled_at' => $at,
        ...mcpAuthParityDestination($fixture),
    ])->assertOk();
    TryPostServer::actingAs($requester)->tool(UpdatePostTool::class, [
        'post_id' => $mcpDraft->id,
        'status' => 'scheduled',
        'scheduled_at' => $at,
    ])->assertOk();

    $created = Post::query()->whereIn('content', ['Created on the web', 'Created through mcp'])->pluck('status')->all();

    expect($created)->toBe([Status::PendingApproval, Status::PendingApproval])
        ->and($webDraft->fresh()->status)->toBe(Status::PendingApproval)
        ->and($mcpDraft->fresh()->status)->toBe(Status::PendingApproval);
});

/**
 * The stored state of every record of a parity fixture.
 *
 * @param  array<string, mixed>  $fixture
 * @return array<string, mixed>
 */
function mcpAuthParitySnapshot(array $fixture): array
{
    return collect($fixture)
        ->filter(fn (mixed $value): bool => $value instanceof Model)
        ->map(fn (Model $record): ?array => $record->fresh()?->makeVisible($record->getHidden())->attributesToArray())
        ->put('posts', Post::query()->where('workspace_id', $fixture['linkedin']->workspace_id)->count())
        ->put('media', Media::query()->where('workspace_id', $fixture['linkedin']->workspace_id)->count())
        ->all();
}

/**
 * @param  array<string, mixed>  $arguments
 */
function mcpAuthParityNamesARecord(array $arguments): bool
{
    return collect(Arr::flatten($arguments))->contains(fn (mixed $value): bool => is_string($value) && Str::isUuid($value));
}

test('every mcp tool that names a record answers not found for one of another workspace and changes nothing, a body reference is a validation error and the bulk idea delete skips it like the web', function () {
    $foreignWorkspace = Workspace::factory()->create();
    $foreignOwner = User::factory()->create(['account_id' => $foreignWorkspace->account_id]);
    $foreignWorkspace->members()->attach($foreignOwner->id, membershipPivot('admin'));
    $foreignWorkspace->update(['user_id' => $foreignOwner->id]);
    $validatesReferences = ['create-post-tool', 'create-posts-tool', 'create-repurpose-tool', 'reorder-idea-stages-tool'];
    $failures = [];

    foreach (mcpAuthParityTools() as $name => [$tool, $refused, $web, $arguments]) {
        $foreign = mcpAuthParityFixture($foreignWorkspace, $foreignOwner);
        $toolArguments = $arguments($foreign);

        if (! mcpAuthParityNamesARecord($toolArguments)) {
            continue;
        }

        $before = mcpAuthParitySnapshot($foreign);
        $response = TryPostServer::actingAs($this->owner->fresh())->tool($tool, $toolArguments);

        try {
            match (true) {
                $name === 'delete-ideas-tool' => $response->assertHasNoErrors(),
                in_array($name, $validatesReferences, true) => $response->assertHasErrors(),
                default => $response->assertHasErrors()->assertSee('not found'),
            };
        } catch (Throwable) {
            $failures[$name] = 'answered without a not found error';
        }

        if (mcpAuthParitySnapshot($foreign) != $before) {
            $failures[$name] = 'changed a record of the other workspace';
        }
    }

    expect($failures)->toBe([]);
});

test('every mcp tool that names a record refuses an id that is not a uuid with a validation error', function () {
    $fixture = mcpAuthParityFixture($this->workspace, $this->owner);
    $failures = [];

    foreach (mcpAuthParityTools() as $name => [$tool, $refused, $web, $arguments]) {
        $toolArguments = $arguments($fixture);

        if (! mcpAuthParityNamesARecord($toolArguments)) {
            continue;
        }

        $malformed = Arr::undot(collect(Arr::dot($toolArguments))
            ->map(fn (mixed $value): mixed => is_string($value) && Str::isUuid($value) ? 'not-a-uuid' : $value)
            ->all());

        try {
            TryPostServer::actingAs($this->owner->fresh())->tool($tool, $malformed)->assertHasErrors()->assertDontSee('SQLSTATE');
        } catch (Throwable $exception) {
            $failures[$name] = class_basename($exception).': '.Str::limit($exception->getMessage(), 120);
        }
    }

    expect($failures)->toBe([]);
});

test('every mcp tool that names a record answers not found for one of another workspace even with nothing else sent, never a validation error', function () {
    $foreignWorkspace = Workspace::factory()->create();
    $foreignOwner = User::factory()->create(['account_id' => $foreignWorkspace->account_id]);
    $foreignWorkspace->members()->attach($foreignOwner->id, membershipPivot('admin'));
    $foreignWorkspace->update(['user_id' => $foreignOwner->id]);
    $validatesReferences = ['create-post-tool', 'create-posts-tool', 'create-repurpose-tool', 'reorder-idea-stages-tool', 'delete-ideas-tool'];
    $failures = [];

    foreach (mcpAuthParityTools() as $name => [$tool, $refused, $web, $arguments]) {
        if (in_array($name, $validatesReferences, true)) {
            continue;
        }

        $foreign = mcpAuthParityFixture($foreignWorkspace, $foreignOwner);
        $recordArguments = collect($arguments($foreign))
            ->filter(fn (mixed $value, string $key): bool => str_ends_with($key, '_id') && is_string($value) && Str::isUuid($value))
            ->all();

        if ($recordArguments === []) {
            continue;
        }

        $before = mcpAuthParitySnapshot($foreign);
        $response = TryPostServer::actingAs($this->owner->fresh())->tool($tool, $recordArguments);

        try {
            $response->assertHasErrors()->assertSee('not found');
        } catch (Throwable) {
            $failures[$name] = 'answered without a not found error';
        }

        if (mcpAuthParitySnapshot($foreign) != $before) {
            $failures[$name] = 'changed a record of the other workspace';
        }
    }

    expect($failures)->toBe([]);
});
