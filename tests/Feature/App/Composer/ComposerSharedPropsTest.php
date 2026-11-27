<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Enums\User\Locale;
use App\Http\Resources\App\HandleInertiaRequests\ComposerResource;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use App\Support\LinkTlds;
use App\Support\PostingSchedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

beforeEach(function () {
    Queue::fake();

    $api = createApiTestToken();
    $this->user = $api['user'];
    $this->workspace = $api['workspace'];
    $this->token = $api['plain_token'];

    $this->channel = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'posting_schedule' => PostingSchedule::empty()->withTime(1, '09:00'),
    ]);
    $this->label = WorkspaceLabel::factory()->create(['workspace_id' => $this->workspace->id, 'name' => 'Launch']);
    $this->signature = WorkspaceSignature::factory()->create(['workspace_id' => $this->workspace->id]);
});

/**
 * @return array<string, mixed>
 */
function composerPage(TestCase $test, string $url): array
{
    return $test->get($url)->assertOk()->viewData('page');
}

/**
 * @param  array<string, string>  $headers
 * @return array<string, mixed>
 */
function composerInertiaVisit(TestCase $test, string $url, string $version, array $headers): array
{
    return $test->withHeaders([
        'X-Inertia' => 'true',
        'X-Inertia-Version' => $version,
        ...$headers,
    ])->get($url)->assertOk()->json();
}

function composerOnceKey(TestCase $test): string
{
    $test->actingAs($test->user);

    return (string) collect(data_get(composerPage($test, route('app.posts.index')), 'onceProps', []))
        ->search(fn (array $once): bool => $once['prop'] === 'composer');
}

/**
 * @return array<string, string>
 */
function composerApiHeaders(TestCase $test): array
{
    return ['Authorization' => "Bearer {$test->token}"];
}

test('guests get no composer or tld list', function () {
    config()->set('trypost.platforms.x.defuse_links', true);

    $props = data_get(composerPage($this, route('login')), 'props');

    expect($props)->not->toHaveKey('composer')
        ->and($props)->not->toHaveKey('xLinkTlds')
        ->and($props)->not->toHaveKey('mediaSources')
        ->and($props)->toHaveKey('languages')
        ->and($props)->toHaveKey('legal');
});

test('the first visit carries the composer once prop keyed by its fingerprint', function () {
    $page = $this->actingAs($this->user)->get(route('app.posts.index'))->assertOk()->viewData('page');
    $key = ComposerResource::key($this->workspace);

    expect(data_get($page, "onceProps.{$key}.prop"))->toBe('composer')
        ->and(data_get($page, "props.composer.accounts.{$this->channel->id}.posting_schedule.1"))->toEqual(['day' => 1, 'enabled' => true, 'times' => ['09:00']])
        ->and(data_get($page, "props.composer.accounts.{$this->channel->id}.has_posting_schedule"))->toBeTrue()
        ->and(data_get($page, "props.composer.accounts.{$this->channel->id}.platform_config.platform"))->toBe(Platform::LinkedIn->value)
        ->and(data_get($page, 'props.composer.labels'))->toEqual([['id' => $this->label->id, 'name' => 'Launch', 'color' => $this->label->color]])
        ->and(data_get($page, 'props.composer.signatures'))->toEqual([['id' => $this->signature->id, 'name' => $this->signature->name, 'content' => $this->signature->content]])
        ->and(collect(data_get($page, 'props.channels'))->firstWhere('id', $this->channel->id))->toHaveKeys(['display_label', 'handle_label']);
});

test('a visit that already holds the current key skips the composer and its queries', function () {
    $key = ComposerResource::key($this->workspace);
    $version = (string) data_get(composerPage($this->actingAs($this->user), route('app.posts.index')), 'version');

    DB::enableQueryLog();
    $page = composerInertiaVisit($this, route('app.posts.index'), $version, ['X-Inertia-Except-Once-Props' => $key]);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect(data_get($page, 'props'))->not->toHaveKey('composer')
        ->and(data_get($page, "onceProps.{$key}.prop"))->toBe('composer')
        ->and($queries->filter(fn (string $query): bool => (bool) preg_match('/content\W+from\W+workspace_signatures/i', $query)))->toBeEmpty();
});

test('a visit holding an older key gets the composer again', function () {
    $version = (string) data_get(composerPage($this->actingAs($this->user), route('app.posts.index')), 'version');

    $page = composerInertiaVisit($this, route('app.posts.index'), $version, ['X-Inertia-Except-Once-Props' => 'composer:stale']);

    expect(data_get($page, "props.composer.accounts.{$this->channel->id}"))->not->toBeNull();
});

test('a partial reload that leaves the composer out never computes its key', function () {
    $version = (string) data_get(composerPage($this->actingAs($this->user), route('app.posts.index')), 'version');

    DB::enableQueryLog();
    $page = composerInertiaVisit($this, route('app.posts.index'), $version, [
        'X-Inertia-Partial-Component' => 'app/publish/Index',
        'X-Inertia-Partial-Data' => 'counts',
    ]);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();

    expect(data_get($page, 'props'))->not->toHaveKey('composer')
        ->and($queries->filter(fn (string $query): bool => str_contains($query, 'workspace_signatures')))->toBeEmpty();
});

test('static shared data is sent once', function () {
    $first = composerPage($this->actingAs($this->user), route('app.posts.index'));
    $again = composerInertiaVisit($this, route('app.posts.index'), (string) data_get($first, 'version'), ['X-Inertia-Except-Once-Props' => 'mediaSources,mediaUploadLimits,languages,legal']);

    expect(data_get($first, 'props'))->toHaveKeys(['mediaSources', 'mediaUploadLimits', 'languages', 'legal'])
        ->and(data_get($again, 'props'))->not->toHaveKeys(['mediaSources', 'mediaUploadLimits', 'languages', 'legal'])
        ->and(data_get($again, 'props'))->toHaveKeys(['auth', 'channels', 'usage', 'features']);
});

test('the composer key changes when its data changes', function (Closure $change) {
    $before = composerOnceKey($this);

    $change($this);

    expect(composerOnceKey($this))->not->toBe($before);
})->with([
    'label created on the web' => [fn (TestCase $test) => $test->actingAs($test->user)->post(route('app.labels.store'), ['name' => 'Promo', 'color' => '#FF0000'])->assertRedirect()],
    'label updated on the web' => [fn (TestCase $test) => $test->actingAs($test->user)->put(route('app.labels.update', $test->label), ['name' => 'Renamed', 'color' => '#00FF00'])->assertRedirect()],
    'label deleted on the web' => [fn (TestCase $test) => $test->actingAs($test->user)->delete(route('app.labels.destroy', $test->label))->assertRedirect()],
    'signature created on the web' => [fn (TestCase $test) => $test->actingAs($test->user)->post(route('app.signatures.store'), ['name' => 'Short', 'content' => 'Thanks'])->assertRedirect()],
    'signature updated on the web' => [fn (TestCase $test) => $test->actingAs($test->user)->put(route('app.signatures.update', $test->signature), ['name' => 'Long', 'content' => 'Thanks a lot'])->assertRedirect()],
    'signature deleted on the web' => [fn (TestCase $test) => $test->actingAs($test->user)->delete(route('app.signatures.destroy', $test->signature))->assertRedirect()],
    'label created through the api' => [fn (TestCase $test) => $test->withHeaders(composerApiHeaders($test))->postJson(route('api.labels.store'), ['name' => 'Api', 'color' => '#FF0000'], ['HTTP_HOST' => 'api.trypost.test'])->assertCreated()],
    'label deleted through the api' => [fn (TestCase $test) => $test->withHeaders(composerApiHeaders($test))->deleteJson(route('api.labels.destroy', $test->label), [], ['HTTP_HOST' => 'api.trypost.test'])->assertSuccessful()],
    'signature created through the api' => [fn (TestCase $test) => $test->withHeaders(composerApiHeaders($test))->postJson(route('api.signatures.store'), ['name' => 'Api', 'content' => 'Hi'], ['HTTP_HOST' => 'api.trypost.test'])->assertCreated()],
    'signature updated through the api' => [fn (TestCase $test) => $test->withHeaders(composerApiHeaders($test))->putJson(route('api.signatures.update', $test->signature), ['name' => 'Api', 'content' => 'Hello'], ['HTTP_HOST' => 'api.trypost.test'])->assertSuccessful()],
    'channel connected' => [fn (TestCase $test) => SocialAccount::factory()->create(['workspace_id' => $test->workspace->id, 'platform' => Platform::X])],
    'channel disconnected' => [fn (TestCase $test) => $test->actingAs($test->user)->delete(route('app.channels.disconnect', $test->channel))->assertRedirect()],
    'posting schedule changed on the web' => [fn (TestCase $test) => $test->actingAs($test->user)->putJson(route('app.channels.posting-schedule.update', $test->channel), [
        'timezone' => 'America/Sao_Paulo',
        'posting_goal' => 2,
        'posting_schedule' => PostingSchedule::empty()->withTime(2, '11:00')->toArray(),
    ])->assertOk()],
    'posting schedule changed through the api' => [fn (TestCase $test) => $test->withHeaders(composerApiHeaders($test))->putJson(route('api.channels.posting-schedule.update', $test->channel), [
        'timezone' => 'America/Sao_Paulo',
        'posting_goal' => 2,
        'posting_schedule' => PostingSchedule::empty()->withTime(3, '10:00')->toArray(),
    ], ['HTTP_HOST' => 'api.trypost.test'])->assertSuccessful()],
]);

test('the composer key follows the workspace and the language', function () {
    $other = Workspace::factory()->create(['account_id' => $this->workspace->account_id, 'user_id' => $this->user->id]);
    $key = ComposerResource::key($this->workspace);

    app()->setLocale(Locale::PortugueseBrazil->value);

    expect(ComposerResource::key($this->workspace))->not->toBe($key)
        ->and(ComposerResource::key($other))->not->toBe(ComposerResource::key($this->workspace));
});

test('the tld list is shared only while x link defusing is on', function (bool $enabled) {
    config()->set('trypost.platforms.x.defuse_links', $enabled);

    $props = data_get($this->actingAs($this->user)->get(route('app.posts.index'))->assertOk()->viewData('page'), 'props');

    if ($enabled) {
        expect(data_get($props, 'xLinkTlds'))->toBe(LinkTlds::all());
    } else {
        expect($props)->not->toHaveKey('xLinkTlds');
    }
})->with(['enabled' => [true], 'disabled' => [false]]);

test('two edits of the same label within one second still change the key', function () {
    $this->freezeSecond();
    $this->label->update(['name' => 'First']);
    $before = ComposerResource::key($this->workspace);

    $this->label->update(['name' => 'Second']);

    expect(ComposerResource::key($this->workspace))->not->toBe($before);
});
