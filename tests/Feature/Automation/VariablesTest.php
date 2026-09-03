<?php

declare(strict_types=1);

use App\Actions\Automation\Node\RunConditionNode;
use App\Actions\Automation\Node\RunFetchRssNode;
use App\Actions\Automation\Node\RunHttpRequestNode;
use App\Enums\UserWorkspace\Role;
use App\Http\Resources\AutomationResource;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Automation\ExpressionResolver;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->refresh();
});

it('stores variable values encrypted and decrypts them for runtime', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'variables' => [
                ['key' => 'API_KEY', 'value' => 'super-secret'],
                ['key' => 'BASE_URL', 'value' => 'https://api.example.com'],
            ],
        ])
        ->assertRedirect();

    $automation->refresh();

    expect($automation->variables[0]['value'])->not->toBe('super-secret');
    expect($automation->resolvedVariables())->toBe([
        'API_KEY' => 'super-secret',
        'BASE_URL' => 'https://api.example.com',
    ]);
});

it('masks variable values in the resource', function () {
    $automation = Automation::factory()->for($this->workspace)->create([
        'variables' => [['key' => 'API_KEY', 'value' => 'super-secret']],
    ]);

    $resource = (new AutomationResource($automation->fresh()))->toArray(request());

    expect($resource['variables'][0]['key'])->toBe('API_KEY');
    expect($resource['variables'][0]['value'])->toBe(Automation::SENSITIVE_PLACEHOLDER);
});

it('keeps the stored value when the masked placeholder is re-saved', function () {
    $automation = Automation::factory()->for($this->workspace)->create([
        'variables' => [['key' => 'API_KEY', 'value' => 'secret-1']],
    ]);

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), [
            'variables' => [['key' => 'API_KEY', 'value' => Automation::SENSITIVE_PLACEHOLDER]],
        ])
        ->assertRedirect();

    expect($automation->fresh()->resolvedVariables())->toBe(['API_KEY' => 'secret-1']);
});

it('resolves {{ variables.X }} in a node template', function () {
    $resolved = app(ExpressionResolver::class)->resolve(
        'Bearer {{ variables.API_KEY }}',
        ['variables' => ['API_KEY' => 'abc123']],
    );

    expect($resolved)->toBe('Bearer abc123');
});

it('merges variables into resolverContext but never into the persisted run context', function () {
    $automation = Automation::factory()->for($this->workspace)->create([
        'variables' => [['key' => 'API_KEY', 'value' => 'secret-xyz']],
    ]);
    $run = AutomationRun::factory()->for($automation)->create(['context' => ['trigger' => ['x' => 1]]]);

    expect($run->resolverContext()['variables'])->toBe(['API_KEY' => 'secret-xyz']);
    expect($run->context)->not->toHaveKey('variables');
    expect($run->fresh()->context)->not->toHaveKey('variables');
});

it('resolves a variable in an HTTP request body without persisting it in the run context', function () {
    Http::fake(['1.1.1.1/*' => Http::response(['ok' => true], 200)]);

    $automation = Automation::factory()->for($this->workspace)->create([
        'variables' => [['key' => 'TOKEN', 'value' => 'abc123']],
    ]);
    $run = AutomationRun::factory()->for($automation)->create(['context' => []]);

    app(RunHttpRequestNode::class)($run, [
        'url' => 'https://1.1.1.1/hook',
        'method' => 'POST',
        'auth_type' => 'none',
        'body_template' => '{"token":"{{ variables.TOKEN }}"}',
    ]);

    Http::assertSent(fn ($request) => $request['token'] === 'abc123');
    expect($run->context)->not->toHaveKey('variables');
});

it('resolves a variable in an RSS feed url', function () {
    Http::fake(['1.1.1.1/*' => Http::response('<rss><channel></channel></rss>', 200)]);

    $automation = Automation::factory()->for($this->workspace)->create([
        'variables' => [['key' => 'HOST', 'value' => '1.1.1.1']],
    ]);
    $run = AutomationRun::factory()->for($automation)->create(['context' => []]);

    app(RunFetchRssNode::class)($run, ['feed_url' => 'https://{{ variables.HOST }}/feed.xml']);

    Http::assertSent(fn ($request) => str_contains($request->url(), '1.1.1.1/feed.xml'));
});

it('resolves a variable in a condition comparison value', function () {
    $automation = Automation::factory()->for($this->workspace)->create([
        'variables' => [['key' => 'EXPECTED', 'value' => 'published']],
    ]);
    $run = AutomationRun::factory()->for($automation)->create([
        'context' => ['trigger' => ['post' => ['status' => 'published']]],
    ]);

    $result = app(RunConditionNode::class)($run, [
        'field' => '{{ trigger.post.status }}',
        'operator' => 'equals',
        'value' => '{{ variables.EXPECTED }}',
    ]);

    expect($result->nextHandle)->toBe('yes');
});

it('rejects an invalid variable key', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->putJson(route('app.automations.update', $automation->id), [
            'variables' => [['key' => 'bad key!', 'value' => 'x']],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['variables.0.key']);
});

it('rejects duplicate variable keys', function () {
    $automation = Automation::factory()->for($this->workspace)->create();

    $this->actingAs($this->user)
        ->putJson(route('app.automations.update', $automation->id), [
            'variables' => [
                ['key' => 'API_KEY', 'value' => 'a'],
                ['key' => 'API_KEY', 'value' => 'b'],
            ],
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['variables.0.key']);
});
