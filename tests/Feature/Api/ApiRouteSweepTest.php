<?php

declare(strict_types=1);

use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AccessToken;
use App\Models\AnalyticsPublication;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\Media;
use App\Models\Post;
use App\Models\PostNote;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\Webhook;
use App\Models\WebhookLog;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every named public API route behind the token middleware.
 *
 * @return list<string>
 */
function apiSweepRouteNames(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => str_starts_with((string) $route->getName(), 'api.') && $route->getName() !== 'api.uploads.store')
        ->map(fn (RoutingRoute $route): string => $route->getName())
        ->values()
        ->all();
}

function apiSweepMethod(string $name): string
{
    return collect(Route::getRoutes()->getByName($name)->methods())->reject(fn (string $method): bool => $method === 'HEAD')->first();
}

/**
 * The route's URI with each parameter replaced by the matching id.
 *
 * @param  array<string, string>  $ids
 */
function apiSweepUri(string $name, array $ids, string $fallback = 'not-a-uuid'): string
{
    $uri = Route::getRoutes()->getByName($name)->uri();

    return '/'.preg_replace_callback('/\{([^}?]+)\??\}/', fn (array $match): string => data_get($ids, $match[1], $fallback), $uri);
}

/**
 * One record of every kind a route can name, in the given workspace.
 *
 * @return array<string, mixed>
 */
function apiSweepRecords(Workspace $workspace): array
{
    $pinterest = SocialAccount::factory()->pinterest()->create(['workspace_id' => $workspace->id]);
    $channel = SocialAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'platform' => Platform::LinkedIn,
        'timezone' => 'UTC',
        'posting_schedule' => collect(range(0, 6))->map(fn (int $day): array => ['day' => $day, 'enabled' => true, 'times' => ['09:00']])->all(),
    ]);
    $slots = $channel->posting_schedule->nextSlots(now()->addMinute(), 'UTC', 2);
    $post = Post::factory()->forAccount($channel, ContentType::LinkedInPost)->create([
        'status' => Status::Scheduled,
        'content' => 'Untouched',
        'schedule_mode' => 'queue',
        'scheduled_at' => $slots[0],
    ]);
    $webhook = Webhook::factory()->create(['workspace_id' => $workspace->id]);

    return [
        'post' => $post,
        'note' => PostNote::factory()->create(['post_id' => $post->id, 'user_id' => $workspace->user_id]),
        'channel' => $channel,
        'tiktok' => SocialAccount::factory()->tiktok()->create(['workspace_id' => $workspace->id]),
        'pinterest' => $pinterest,
        'freeSlot' => $slots[1]->toIso8601ZuluString(),
        'signature' => WorkspaceSignature::factory()->create(['workspace_id' => $workspace->id]),
        'label' => WorkspaceLabel::factory()->create(['workspace_id' => $workspace->id]),
        'ideaStage' => IdeaStage::factory()->create(['workspace_id' => $workspace->id]),
        'idea' => Idea::factory()->create(['workspace_id' => $workspace->id]),
        'repurpose' => Repurpose::factory()->active()->create([
            'workspace_id' => $workspace->id,
            'source_social_account_id' => SocialAccount::factory()->instagram()->create(['workspace_id' => $workspace->id])->id,
        ]),
        'webhook' => $webhook,
        'webhookLog' => WebhookLog::factory()->create(['webhook_id' => $webhook->id]),
        'publication' => AnalyticsPublication::factory()->create(['workspace_id' => $workspace->id, 'social_account_id' => $channel->id]),
        'upload' => Media::factory()->temporaryUpload($workspace)->create(),
    ];
}

/**
 * A body the route accepts when the record belongs to the caller.
 *
 * @param  array<string, mixed>  $own
 * @param  array<string, mixed>  $foreign
 * @return array<string, mixed>
 */
function apiSweepBody(string $name, array $own, array $foreign): array
{
    return match ($name) {
        'api.posts.update' => ['status' => 'draft', 'content' => 'Hijacked'],
        'api.posts.recurrence.update' => ['interval' => 1, 'frequency' => 'day', 'times' => 2],
        'api.posts.store-media' => ['media' => UploadedFile::fake()->createWithContent('photo.png', file_get_contents(__DIR__.'/../../fixtures/1x1.png'))],
        'api.posts.attach-media-from-upload' => ['upload_token' => $own['upload']->upload_token],
        'api.posts.attach-media-from-url' => ['urls' => [['url' => 'https://example.com/photo.jpg']]],
        'api.posts.notes.store', 'api.posts.notes.update' => ['body' => 'Hijacked'],
        'api.signatures.update' => ['name' => 'Hijacked', 'content' => 'Hijacked'],
        'api.labels.update' => ['name' => 'Hijacked', 'color' => '#112233'],
        'api.ideas.update' => ['title' => 'Hijacked'],
        'api.ideas.move' => ['idea_stage_id' => $own['ideaStage']->id],
        'api.idea-stages.update' => ['name' => 'Hijacked'],
        'api.social-accounts.boards.store' => ['name' => 'Hijacked'],
        'api.channels.posting-schedule.update' => ['timezone' => 'America/Sao_Paulo', 'posting_goal' => 2, 'posting_schedule' => null],
        'api.channels.posting-schedule.generate' => ['mode' => 'goal', 'goal' => 3],
        'api.channels.posting-schedule.copy' => ['from' => $own['channel']->id],
        'api.channels.queue.order' => ['post_ids' => [$foreign['post']->id]],
        'api.channels.queue.slot' => ['post_id' => $foreign['post']->id, 'slot_at' => $foreign['freeSlot']],
        'api.repurposes.update' => ['source_social_account_id' => $own['pinterest']->id, 'source_format' => 'reel'],
        'api.webhooks.update' => ['endpoint' => 'https://example.com/hijacked', 'events' => ['post.published']],
        default => [],
    };
}

/**
 * Route parameter name => id of the record it names.
 *
 * @param  array<string, mixed>  $records
 * @return array<string, string>
 */
function apiSweepIds(string $name, array $records, string $apiTokenId): array
{
    $account = match ($name) {
        'api.social-accounts.boards', 'api.social-accounts.boards.store' => $records['pinterest'],
        'api.social-accounts.tiktok-creator-info' => $records['tiktok'],
        default => $records['channel'],
    };

    return [
        'post' => $records['post']->id,
        'note' => $records['note']->id,
        'signature' => $records['signature']->id,
        'idea' => $records['idea']->id,
        'ideaStage' => $records['ideaStage']->id,
        'label' => $records['label']->id,
        'account' => $account->id,
        'repurpose' => $records['repurpose']->id,
        'webhook' => $records['webhook']->id,
        'webhookLog' => $records['webhookLog']->id,
        'apiToken' => $apiTokenId,
        'publication' => $records['publication']->id,
    ];
}

/**
 * The stored state of every record, to prove a refused call changed nothing.
 *
 * @param  array<string, mixed>  $records
 * @return array<string, mixed>
 */
function apiSweepSnapshot(array $records, string $apiTokenId): array
{
    return [
        ...collect($records)
            ->filter(fn (mixed $record): bool => is_object($record))
            ->map(fn (object $record): ?array => $record->fresh()?->makeVisible($record->getHidden())->attributesToArray())
            ->all(),
        'post_media' => $records['post']->ownedMedia()->count(),
        'apiToken' => AccessToken::query()->find($apiTokenId)?->attributesToArray(),
    ];
}

beforeEach(function () {
    Queue::fake();
});

/**
 * @return list<string>
 */
function apiSweepRoutesWithIds(): array
{
    return collect(apiSweepRouteNames())
        ->filter(fn (string $name): bool => str_contains(Route::getRoutes()->getByName($name)->uri(), '{'))
        ->values()
        ->all();
}

test('the sweep reaches the public api routes and not the signed upload', function () {
    expect(apiSweepRouteNames())->toContain('api.posts.store', 'api.webhooks.replay', 'api.api-keys.destroy')
        ->not->toContain('api.uploads.store')
        ->and(apiSweepRoutesWithIds())->toContain('api.posts.update', 'api.channels.queue.slot');
});

test('every api route answers 401 without a bearer token', function () {
    $failures = collect(apiSweepRouteNames())
        ->mapWithKeys(fn (string $name): array => [$name => $this->json(apiSweepMethod($name), apiSweepUri($name, [], (string) Str::uuid()))->status()])
        ->reject(fn (int $status): bool => $status === Response::HTTP_UNAUTHORIZED)
        ->all();

    expect($failures)->toBe([]);
});

test('every api route refuses a member who is not an admin with 403 before touching anything', function (string $access) {
    $failures = [];

    foreach (apiSweepRouteNames() as $name) {
        $owner = createApiTestToken();
        $member = workspaceMember($owner['workspace'], $access);
        $token = passportToken($member, $owner['workspace']);
        $records = apiSweepRecords($owner['workspace']);
        $ids = apiSweepIds($name, $records, $owner['user']->tokens()->value('id'));
        $before = apiSweepSnapshot($records, $ids['apiToken']);

        app('auth')->forgetGuards();
        $response = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->json(apiSweepMethod($name), apiSweepUri($name, $ids), apiSweepBody($name, $records, $records));

        if ($response->status() !== Response::HTTP_FORBIDDEN || $response->json('message') !== 'Insufficient workspace permissions.' || apiSweepSnapshot($records, $ids['apiToken']) != $before) {
            $failures[$name] = $response->status();
        }
    }

    expect($failures)->toBe([]);
})->with(['member', 'approval']);

test('every api route that names a record answers 404 for an id that is not a uuid', function () {
    $result = createApiTestToken();

    $failures = collect(apiSweepRoutesWithIds())
        ->mapWithKeys(fn (string $name): array => [$name => $this->withHeaders(['Authorization' => "Bearer {$result['plain_token']}"])->json(apiSweepMethod($name), apiSweepUri($name, []))->status()])
        ->reject(fn (int $status): bool => $status === Response::HTTP_NOT_FOUND)
        ->all();

    expect($failures)->toBe([]);
});

test('every api route that names a record answers 404 for one of another workspace and changes nothing', function () {
    $failures = [];

    foreach (apiSweepRoutesWithIds() as $name) {
        $result = createApiTestToken();
        $other = createApiTestToken();
        $own = apiSweepRecords($result['workspace']);
        $foreign = apiSweepRecords($other['workspace']);
        $ids = apiSweepIds($name, $foreign, $other['user']->tokens()->value('id'));
        $before = apiSweepSnapshot($foreign, $ids['apiToken']);

        app('auth')->forgetGuards();
        $response = $this->withHeaders(['Authorization' => "Bearer {$result['plain_token']}"])
            ->json(apiSweepMethod($name), apiSweepUri($name, $ids), apiSweepBody($name, $own, $foreign));

        if ($response->status() !== Response::HTTP_NOT_FOUND
            || apiSweepSnapshot($foreign, $ids['apiToken']) != $before
            || $own['upload']->fresh()?->upload_token !== $own['upload']->upload_token) {
            $failures[$name] = $response->status();
        }
    }

    expect($failures)->toBe([]);
});

test('a malformed id inside a body is a validation error, never a database error', function () {
    $result = createApiTestToken();
    $records = apiSweepRecords($result['workspace']);
    $draft = Post::factory()->forAccount($records['channel'], ContentType::LinkedInPost)->draft()->create();
    $tomorrow = now()->addDay()->toIso8601String();
    $calls = [
        'post create' => ['POST', route('api.posts.store'), ['content' => 'Hello', 'social_account_id' => 'not-a-uuid', 'content_type' => 'linkedin_post'], 'social_account_id'],
        'post create scheduled' => ['POST', route('api.posts.store'), ['content' => 'Hello', 'status' => 'scheduled', 'scheduled_at' => $tomorrow, 'social_account_id' => 'not-a-uuid'], 'social_account_id'],
        'post update' => ['PUT', route('api.posts.update', $draft), ['status' => 'draft', 'label_ids' => ['not-a-uuid']], 'label_ids.0'],
        'post update scheduled' => ['PUT', route('api.posts.update', $draft), ['status' => 'scheduled', 'scheduled_at' => $tomorrow, 'label_ids' => ['not-a-uuid']], 'label_ids.0'],
        'repurpose update' => ['PUT', route('api.repurposes.update', $records['repurpose']), ['destinations' => [['social_account_id' => 'not-a-uuid', 'content_type' => 'linkedin_post']]], 'destinations.0.social_account_id'],
    ];

    foreach ($calls as [$method, $uri, $body, $field]) {
        app('auth')->forgetGuards();
        $this->withHeaders(['Authorization' => "Bearer {$result['plain_token']}"])
            ->json($method, $uri, $body)
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);
    }

    expect($draft->fresh()->social_account_id)->toBe($records['channel']->id)
        ->and($draft->fresh()->content)->toBe($draft->content);
});

test('every api route that names a record answers 404 for one of another workspace even with an empty body, never 422', function () {
    $failures = [];

    foreach (apiSweepRoutesWithIds() as $name) {
        $result = createApiTestToken();
        $other = createApiTestToken();
        $foreign = apiSweepRecords($other['workspace']);
        $ids = apiSweepIds($name, $foreign, $other['user']->tokens()->value('id'));
        $before = apiSweepSnapshot($foreign, $ids['apiToken']);

        app('auth')->forgetGuards();
        $response = $this->withHeaders(['Authorization' => "Bearer {$result['plain_token']}"])
            ->json(apiSweepMethod($name), apiSweepUri($name, $ids), []);

        if ($response->status() !== Response::HTTP_NOT_FOUND || apiSweepSnapshot($foreign, $ids['apiToken']) != $before) {
            $failures[$name] = $response->status();
        }
    }

    expect($failures)->toBe([]);
});
