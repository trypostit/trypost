<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

function firstCommentPost(string $factoryState, ContentType $contentType, array $attributes = []): Post
{
    Http::fake(['*' => Http::response([], 200)]);

    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id, 'account_id' => $user->account_id]);
    $workspace->members()->attach($user->id, membershipPivot('admin'));
    $user->update(['current_workspace_id' => $workspace->id]);
    subscribeAccount($user->account);
    connectAiTo($user);

    $account = SocialAccount::factory()->{$factoryState}()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($account, $contentType)->create([
        'user_id' => $user->id,
        'content' => 'Our launch is live',
        ...$attributes,
    ]);

    test()->actingAs($user);

    return $post;
}

test('the first comment shows in the linkedin preview and is kept after save and reopen', function (string $factoryState, ContentType $contentType) {
    $post = firstCommentPost($factoryState, $contentType);
    $field = "@first-comment-{$post->social_account_id}";

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, $field);

    $page->fill($field, 'Read the full story at example.com');
    waitForText($page, '@preview-first-comment', 'Read the full story at example.com');
    $page->assertSeeIn("@first-comment-count-{$post->social_account_id}", '34/1250')
        ->assertNoJavaScriptErrors();

    $page->click('@composer-save-draft');
    waitForGone($page, '@post-composer-dialog');

    expect($post->fresh()->meta['first_comment'])->toBe(['text' => 'Read the full story at example.com']);

    $reopened = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($reopened, $field);
    $reopened->assertValue($field, 'Read the full story at example.com')
        ->assertSeeIn('@preview-first-comment', 'Read the full story at example.com')
        ->assertNoJavaScriptErrors();
})->with([
    'profile' => ['linkedin', ContentType::LinkedInPost],
    'page' => ['linkedinPage', ContentType::LinkedInPagePost],
]);

test('a channel whose network takes no first comment shows no field', function () {
    $post = firstCommentPost('x', ContentType::XPost);

    $page = visit(route('app.posts.edit', $post))->resize(1280, 900);
    waitFor($page, "@composer-type-{$post->social_account_id}-x_poll");

    $page->assertMissing("@first-comment-{$post->social_account_id}")
        ->assertNoJavaScriptErrors();
});
