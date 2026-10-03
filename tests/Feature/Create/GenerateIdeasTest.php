<?php

declare(strict_types=1);

use App\Ai\Agents\IdeaGenerator;
use App\Enums\User\Locale;
use App\Models\Idea;
use App\Models\IdeaStage;
use App\Models\User;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Illuminate\Auth\Access\Response as AuthResponse;
use Illuminate\Support\Facades\Gate;
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

function generateIdeasPayload(array $overrides = []): array
{
    return array_merge([
        'count' => 3,
        'business' => 'Handmade ceramics studio',
        'audience' => 'Home decor lovers',
    ], $overrides);
}

function generateIdeasFake(): void
{
    IdeaGenerator::fake([['ideas' => [
        ['title' => 'A', 'body' => 'a'],
        ['title' => 'B', 'body' => 'b'],
        ['title' => 'C', 'body' => 'c'],
    ]]]);
}

test('generates ideas appended to the chosen stage', function () {
    generateIdeasFake();
    $stage = IdeaStage::factory()->create(['workspace_id' => $this->workspace->id]);
    $existing = Idea::factory()->inStage($stage)->create(['position' => 0, 'title' => 'Existing']);

    $this->actingAs($this->user)
        ->postJson(route('app.create.ideas.generate'), generateIdeasPayload(['idea_stage_id' => $stage->id]))
        ->assertStatus(Response::HTTP_CREATED)
        ->assertJsonCount(3);

    expect(Idea::where('idea_stage_id', $stage->id)->orderBy('position')->pluck('title')->all())
        ->toBe(['Existing', 'A', 'B', 'C']);

    IdeaGenerator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->count === 3);
});

test('the agent is prompted exactly once', function () {
    generateIdeasFake();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload())->assertCreated();

    $prompted = 0;
    IdeaGenerator::assertPrompted(function (AgentPrompt $prompt) use (&$prompted): bool {
        $prompted++;

        return true;
    });
    expect($prompted)->toBe(1)->and(Idea::count())->toBe(3);
});

test('a denied AI gate returns 402 and never calls the model', function () {
    IdeaGenerator::fake([['ideas' => []]]);
    Gate::before(fn (User $user, string $ability): ?AuthResponse => $ability === 'useAi'
        ? AuthResponse::deny(__('billing.flash.subscription_required'))
        : null);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload())
        ->assertStatus(Response::HTTP_PAYMENT_REQUIRED)
        ->assertJsonPath('message', __('billing.flash.subscription_required'));

    expect(Idea::count())->toBe(0);
    IdeaGenerator::assertNeverPrompted();
});

test('validates the answers', function () {
    IdeaGenerator::fake();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload(['business' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors(['business']);
    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload(['audience' => null]))
        ->assertUnprocessable()->assertJsonValidationErrors(['audience']);
    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload(['notes' => str_repeat('a', 10001)]))
        ->assertUnprocessable()->assertJsonValidationErrors(['notes']);

    IdeaGenerator::assertNeverPrompted();
});

test('validates the count and the stage', function (mixed $count) {
    IdeaGenerator::fake();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload(['count' => $count]))
        ->assertUnprocessable()->assertJsonValidationErrors(['count']);
})->with([0, 11]);

test('a foreign stage is rejected', function () {
    IdeaGenerator::fake();

    $this->actingAs($this->user)
        ->postJson(route('app.create.ideas.generate'), generateIdeasPayload(['idea_stage_id' => IdeaStage::factory()->create()->id]))
        ->assertUnprocessable()->assertJsonValidationErrors(['idea_stage_id']);
});

test('without a stage the ideas land in unassigned', function () {
    generateIdeasFake();

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload())->assertCreated();

    expect(Idea::whereNull('idea_stage_id')->count())->toBe(3);
});

test('the prompt uses the requesters language and only their answers', function () {
    generateIdeasFake();
    $this->user->update(['locale' => Locale::PortugueseBrazil]);

    $this->actingAs($this->user->fresh())->postJson(route('app.create.ideas.generate'), generateIdeasPayload())->assertCreated();

    IdeaGenerator::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->agent->locale === Locale::PortugueseBrazil
        && str_contains($prompt->agent->instructions(), 'Brazilian Portuguese (pt-BR)')
        && str_contains($prompt->agent->instructions(), 'Handmade ceramics studio'));

    $parameters = collect((new ReflectionClass(IdeaGenerator::class))->getConstructor()->getParameters())
        ->map(fn (ReflectionParameter $parameter) => (string) $parameter->getType());

    expect($parameters->contains(Workspace::class))->toBeFalse();
});

test('a user outside the workspace cannot generate ideas', function () {
    IdeaGenerator::fake();
    $outsider = workspaceOutsider($this->workspace);

    $this->actingAs($outsider->fresh())->postJson(route('app.create.ideas.generate'), generateIdeasPayload())->assertForbidden();
    IdeaGenerator::assertNeverPrompted();
});

test('user data reaches the model unescaped', function () {
    $instructions = (new IdeaGenerator(Locale::English, 3, "Joe's R&D <lab>", 'Q&A "fans"', "Don't use &"))->instructions();

    expect($instructions)->toContain("Joe's R&D <lab>")
        ->toContain('Q&A "fans"')
        ->toContain("Don't use &");
});

test('model output is bounded and empty titles are skipped', function () {
    IdeaGenerator::fake([['ideas' => [
        ['title' => str_repeat('x', 400), 'body' => str_repeat('y', 12000)],
        ['title' => '   ', 'body' => 'b'],
        ['title' => 'Kept', 'body' => 'c'],
    ]]]);

    $this->actingAs($this->user)->postJson(route('app.create.ideas.generate'), generateIdeasPayload())->assertCreated()->assertJsonCount(2);

    expect(Idea::orderBy('position')->pluck('title')->map(fn (string $title): int => mb_strlen($title))->all())->toBe([255, 4])
        ->and(Idea::orderBy('position')->pluck('body')->map(fn (string $body): int => mb_strlen($body))->all())->toBe([AiPromptRules::PROMPT_MAX_LENGTH, 1]);
});
