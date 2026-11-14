<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\ApiKey\ListApiKeysTool;
use App\Mcp\Tools\Label\CreateLabelTool;
use App\Mcp\Tools\Label\ListLabelsTool;
use App\Mcp\Tools\Signature\ListSignaturesTool;
use App\Mcp\Tools\SocialAccount\ListSocialAccountsTool;
use App\Mcp\Tools\Webhook\ListWebhooksTool;
use App\Models\AccessToken;
use App\Models\Post;
use App\Models\Repurpose;
use App\Models\SocialAccount;
use App\Models\Webhook;
use App\Models\WorkspaceLabel;
use App\Models\WorkspaceSignature;
use Illuminate\Database\Eloquent\Factories\Sequence;
use Tests\TestCase;

beforeEach(function () {
    ['user' => $this->user, 'workspace' => $this->workspace, 'token' => $this->token] = parityContext();
    config(['app.pagination.default' => 2]);
});

test('the posts list paginates with the app default and exposes per_page', function () {
    Post::factory()->count(3)->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id]);

    $this->withHeaders(parityApi($this->token))->getJson(route('api.posts.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2);
});

test('the repurposes list paginates with the app default and exposes per_page', function () {
    foreach (range(1, 3) as $ignored) {
        Repurpose::factory()->create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'source_social_account_id' => SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id])->id,
        ]);
    }

    $this->withHeaders(parityApi($this->token))->getJson(route('api.repurposes.index'))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2);
});

test('the bare lists paginate with the app default on the api and mcp, ignore a requested page size and keep the web order', function (string $route, string $tool, string $key, Closure $seed, ?Closure $web) {
    $seed($this);
    $expectedTotal = $route === 'api.api-keys.index' ? 4 : 3;

    $first = $this->withHeaders(parityApi($this->token))->getJson(route($route, ['per_page' => 50]))->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', $expectedTotal);
    $second = $this->withHeaders(parityApi($this->token))->getJson(route($route, ['page' => 2]))->assertOk();
    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool($tool)->assertOk()->assertStructuredContent(parityMcpPage($key, $first));
    TryPostServer::actingAs($this->user)->tool($tool, ['page' => 2])->assertOk()->assertStructuredContent(parityMcpPage($key, $second));

    $apiIds = [...collect($first->json('data'))->pluck('id'), ...collect($second->json('data'))->pluck('id')];

    if ($web !== null) {
        expect($apiIds)->toBe(collect($web($this->actingAs($this->user)))->pluck('id')->take(4)->all());
    }
})->with([
    'labels' => ['api.labels.index', ListLabelsTool::class, 'labels', fn (TestCase $t) => WorkspaceLabel::factory()->count(3)->sequence(fn (Sequence $sequence) => ['created_at' => now()->subMinutes($sequence->index)])->create(['workspace_id' => $t->workspace->id]), null],
    'signatures' => ['api.signatures.index', ListSignaturesTool::class, 'signatures', fn (TestCase $t) => WorkspaceSignature::factory()->count(3)->sequence(fn (Sequence $sequence) => ['created_at' => now()->subMinutes($sequence->index)])->create(['workspace_id' => $t->workspace->id]), null],
    'webhooks' => ['api.webhooks.index', ListWebhooksTool::class, 'webhooks', fn (TestCase $t) => Webhook::factory()->count(3)->sequence(fn (Sequence $sequence) => ['created_at' => now()->subMinutes($sequence->index)])->create(['workspace_id' => $t->workspace->id]), fn (TestCase $t) => $t->get(route('app.webhooks.index'))->assertOk()->viewData('page')['props']['webhooks']],
    'social accounts' => ['api.social-accounts.index', ListSocialAccountsTool::class, 'social_accounts', fn (TestCase $t) => SocialAccount::factory()->count(3)->sequence(fn (Sequence $sequence) => ['position' => 2 - $sequence->index])->create(['workspace_id' => $t->workspace->id]), fn (TestCase $t) => collect([1, 2])->flatMap(fn (int $page) => $t->get(route('app.workspace.channels', ['page' => $page]))->assertOk()->viewData('page')['props']['connectedChannels']['data'])->all()],
    'api keys' => ['api.api-keys.index', ListApiKeysTool::class, 'api_keys', fn (TestCase $t) => collect(range(1, 3))->each(fn (int $index) => AccessToken::query()->findOrFail($t->user->createToken("Key {$index}")->token->id)->forceFill(['workspace_id' => $t->workspace->id, 'created_at' => now()->addMinutes($index)])->saveQuietly()), fn (TestCase $t) => $t->get(route('app.api-keys.index'))->assertOk()->viewData('page')['props']['apiTokens']],
]);

test('a bad label color is rejected by the api, the web and mcp with the same message', function () {
    $payload = ['name' => 'Bad', 'color' => 'red'];

    $api = $this->withHeaders(parityApi($this->token))->postJson(route('api.labels.store'), $payload)->assertUnprocessable();
    $web = $this->actingAs($this->user)->postJson(route('app.labels.store'), $payload)->assertUnprocessable();

    expect($api->json('errors.color'))->toBe($web->json('errors.color'));

    auth()->forgetGuards();

    TryPostServer::actingAs($this->user)->tool(CreateLabelTool::class, $payload)
        ->assertHasErrors([$api->json('errors.color.0')]);
});
