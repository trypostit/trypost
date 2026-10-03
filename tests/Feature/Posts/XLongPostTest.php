<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\ContentFitsPlatformLimits;
use App\Services\Social\XPublisher;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('the content rule measures an x account against its own tier', function (array $meta, bool $passes) {
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'meta' => $meta]);
    $validator = Validator::make(
        ['content' => str_repeat('a', 1000)],
        ['content' => [new ContentFitsPlatformLimits(collect([$account]))]],
    );

    expect($validator->passes())->toBe($passes);
})->with([
    'free account' => [[], false],
    'premium account' => [['x_subscription_type' => 'Premium'], true],
]);

test('the composer receives the account limit', function () {
    $premium = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'meta' => ['x_subscription_type' => 'Premium']]);
    $free = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id, 'meta' => []]);

    $this->actingAs($this->user)->getJson(route('app.posts.composer-data'))
        ->assertOk()
        ->assertJsonPath("platformConfigs.{$premium->id}.maxContentLength", 25000)
        ->assertJsonPath("platformConfigs.{$free->id}.maxContentLength", 280);
});

test('a premium x account publishes a long post', function () {
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'meta' => ['x_subscription_type' => 'Premium'],
        'token_expires_at' => now()->addHours(2),
    ]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'content' => str_repeat('a', 2000)]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::X, 'content_type' => ContentType::XPost,
    ]);
    Http::fake([config('trypost.platforms.x.api').'/tweets' => Http::response(['data' => ['id' => '99']], 201)]);

    expect((new XPublisher)->publish($postPlatform)['id'])->toBe('99');
    Http::assertSent(fn (Request $request): bool => mb_strlen((string) data_get($request->data(), 'text')) === 2000);
});

test('an account that lost premium fails the long post at publish', function () {
    $account = SocialAccount::factory()->x()->create([
        'workspace_id' => $this->workspace->id,
        'meta' => ['x_subscription_type' => 'None'],
        'token_expires_at' => now()->addHours(2),
    ]);
    $post = Post::factory()->create(['workspace_id' => $this->workspace->id, 'user_id' => $this->user->id, 'content' => str_repeat('a', 2000)]);
    $postPlatform = PostPlatform::factory()->create([
        'post_id' => $post->id, 'social_account_id' => $account->id, 'platform' => Platform::X, 'content_type' => ContentType::XPost,
    ]);
    Http::fake();

    expect(fn () => (new XPublisher)->publish($postPlatform))->toThrow(Exception::class, 'limit of 280 characters');
    Http::assertNothingSent();
});

test('post content accepts up to the long post size on the api', function () {
    $result = createApiTestToken();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $result['workspace']->id, 'meta' => ['x_subscription_type' => 'Premium']]);

    $this->withHeaders(['Authorization' => "Bearer {$result['plain_token']}"])
        ->postJson(route('api.posts.store'), [
            'content' => str_repeat('a', 20000),
            'platforms' => [['social_account_id' => $account->id, 'content_type' => ContentType::XPost->value]],
        ])->assertCreated();
});

test('post content stores the longest post in four byte characters', function () {
    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
        'content' => str_repeat('😀', Post::CONTENT_MAX_LENGTH),
    ]);

    expect(mb_strlen((string) $post->fresh()->content))->toBe(Post::CONTENT_MAX_LENGTH);
});

test('the post content cap counts the text a reader sees, not the editor markup', function (string $content, bool $accepted) {
    $result = createApiTestToken();
    $account = SocialAccount::factory()->x()->create(['workspace_id' => $result['workspace']->id, 'meta' => ['x_subscription_type' => 'Premium']]);

    $response = $this->withHeaders(['Authorization' => "Bearer {$result['plain_token']}"])
        ->postJson(route('api.posts.store'), [
            'content' => $content,
            'platforms' => [['social_account_id' => $account->id, 'content_type' => ContentType::XPost->value]],
        ]);

    $accepted ? $response->assertCreated() : $response->assertUnprocessable()->assertJsonValidationErrors(['content']);
})->with([
    'markup over the cap, text under it' => [str_repeat('<p>abcd</p>', 4000), true],
    'text over the cap' => [str_repeat('a', 25001), false],
]);
