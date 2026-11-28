<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Enums\User\Locale;
use App\Models\Idea;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Illuminate\Auth\Access\Response as AuthResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Gate;
use Laravel\Ai\Exceptions\ProviderOverloadedException;
use Laravel\Ai\Prompts\AgentPrompt;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config(['trypost.self_hosted' => false]);

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->user = $this->user->fresh();
    subscribeAccount($this->user->account);
});

function generateIdeaPayload(array $overrides = []): array
{
    return array_merge([
        'business' => 'Handmade ceramics studio',
        'audience' => 'Home decor lovers',
    ], $overrides);
}

function generateIdeaFake(): void
{
    IdeaGenerator::fake([['title' => 'Behind the kiln', 'body' => 'Show the firing process.']]);
}

test('returns one generated idea without storing it', function () {
    generateIdeaFake();

    $this->actingAs($this->user)
        ->postJson(route('app.create.ideas.generate'), generateIdeaPayload())
        ->assertOk()
        ->assertExactJson(['title' => 'Behind the kiln', 'body' => 'Show the firing process.']);

    expect(Idea::count())->toBe(0);
});

test('the agent is prompted exactly once per request', function () {
    generateIdeaFake();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload())->assertOk();

    $prompted = 0;
    IdeaGenerator::assertPrompted(function (AgentPrompt $prompt) use (&$prompted): bool {
        $prompted++;

        return true;
    });
    expect($prompted)->toBe(1);
});

test('a denied AI gate returns 402 and never calls the model', function () {
    IdeaGenerator::fake();
    Gate::before(fn (User $user, string $ability): ?AuthResponse => $ability === 'useAi'
        ? AuthResponse::deny(__('billing.flash.subscription_required'))
        : null);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload())
        ->assertStatus(Response::HTTP_PAYMENT_REQUIRED)
        ->assertJsonPath('message', __('billing.flash.subscription_required'));

    expect(Idea::count())->toBe(0);
    IdeaGenerator::assertNeverPrompted();
});

test('the audience and the business are required', function (string $field) {
    IdeaGenerator::fake();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload([$field => null]))
        ->assertUnprocessable()->assertJsonValidationErrors([$field]);

    IdeaGenerator::assertNeverPrompted();
})->with(['business', 'audience']);

test('notes are optional and bounded', function () {
    generateIdeaFake();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload(['notes' => str_repeat('a', AiPromptRules::PROMPT_MAX_LENGTH + 1)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['notes']);
    IdeaGenerator::assertNeverPrompted();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload())->assertOk();
    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload(['notes' => 'Launch next week']))->assertOk();

    IdeaGenerator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->notes === 'Launch next week'
        && str_contains($prompt->agent->instructions(), 'Launch next week'));
});

test('the prompt uses the requesters language and only their answers', function () {
    generateIdeaFake();
    $this->user->update(['locale' => Locale::PortugueseBrazil]);

    $this->actingAs($this->user->fresh())->postJson(route('app.create.ideas.generate'), generateIdeaPayload())->assertOk();

    IdeaGenerator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->locale === Locale::PortugueseBrazil
        && str_contains($prompt->agent->instructions(), 'Brazilian Portuguese (pt-BR)')
        && str_contains($prompt->agent->instructions(), 'Handmade ceramics studio')
        && str_contains($prompt->agent->instructions(), 'Home decor lovers'));

    $parameters = collect((new ReflectionClass(IdeaGenerator::class))->getConstructor()->getParameters())
        ->map(fn (ReflectionParameter $parameter) => (string) $parameter->getType());

    expect($parameters->contains(Workspace::class))->toBeFalse();
});

test('a user outside the workspace cannot generate ideas', function () {
    IdeaGenerator::fake();
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())->postJson(route('app.create.ideas.generate'), generateIdeaPayload())->assertForbidden();
    IdeaGenerator::assertNeverPrompted();
});

test('user data reaches the model unescaped', function () {
    $instructions = (new IdeaGenerator(Locale::English, "Joe's R&D <lab>", 'Q&A "fans"', "Don't use &"))->instructions();

    expect($instructions)->toContain("Joe's R&D <lab>")
        ->toContain('Q&A "fans"')
        ->toContain("Don't use &");
});

test('model output is bounded', function () {
    IdeaGenerator::fake([['title' => str_repeat('x', 400), 'body' => str_repeat('y', 12000)]]);

    $response = $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload())->assertOk();

    expect(mb_strlen($response->json('title')))->toBe(255)
        ->and(mb_strlen($response->json('body')))->toBe(AiPromptRules::PROMPT_MAX_LENGTH);
});

test('an empty title from the model fails with a message', function () {
    IdeaGenerator::fake([['title' => '   ', 'body' => 'b']]);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeaPayload())
        ->assertStatus(Response::HTTP_BAD_GATEWAY)
        ->assertJsonPath('message', __('create.ideas.errors.generate_failed'));

    expect(Idea::count())->toBe(0);
});

test('a failing provider returns a translated bad gateway', function (Throwable $failure) {
    IdeaGenerator::fake([fn () => throw $failure]);

    $this->actingAs($this->user)
        ->postJson(route('app.create.ideas.generate'), generateIdeaPayload())
        ->assertStatus(Response::HTTP_BAD_GATEWAY)
        ->assertJsonPath('message', __('create.ideas.errors.generate_failed'));
})->with([
    'overloaded' => fn () => new ProviderOverloadedException('overloaded'),
    'connection' => fn () => new ConnectionException('timeout'),
]);
