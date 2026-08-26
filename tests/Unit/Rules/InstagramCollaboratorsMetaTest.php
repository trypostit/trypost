<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Rules\InstagramCollaboratorsMeta;
use App\Support\PostPlatformMetaRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $platform
 * @param  list<mixed>|string  $collaborators
 * @return array<string, list<string>>
 */
function runCollaboratorsMetaRule(array|string $collaborators, array $platform): array
{
    $data = [
        'platforms' => [array_merge($platform, ['meta' => ['collaborators' => $collaborators]])],
    ];
    $validator = Validator::make($data, []);
    $rule = (new InstagramCollaboratorsMeta)->setData($data)->setValidator($validator);
    $parentErrors = [];

    $rule->validate('platforms.0.meta.collaborators', $collaborators, function (string $message) use (&$parentErrors): void {
        $parentErrors[] = $message;
    });

    return [
        'parent' => $parentErrors,
        'items' => $validator->errors()->messages(),
    ];
}

test('fails when the collaborator is the connected instagram account', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['@TestUser'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['items']['platforms.0.meta.collaborators.0'] ?? [])->toBe([__('posts.form.instagram.collaborators_self')]);
});

test('passes when the collaborator is a different username', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['host_one'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['parent'])->toBe([])
        ->and($errors['items'])->toBe([]);
});

test('fails on update when the post platform account matches', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagramFacebook()->create([
        'workspace_id' => $workspace->id,
        'username' => 'page_ig',
    ]);
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => User::factory(),
    ]);
    $platform = PostPlatform::factory()->instagramFacebook()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
    ]);

    $errors = runCollaboratorsMetaRule(['page_ig'], [
        'id' => $platform->id,
    ]);

    expect($errors['items']['platforms.0.meta.collaborators.0'] ?? [])->toBe([__('posts.form.instagram.collaborators_self')]);
});

test('fails on a duplicate collaborator with a dedicated message', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['Host_One', 'host_one'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['items']['platforms.0.meta.collaborators.1'] ?? [])
        ->toBe([__('posts.form.instagram.collaborators_duplicate')]);
});

test('fails max after counting unique valid usernames', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
    ]);

    $errors = runCollaboratorsMetaRule(['a', 'b', 'c', 'd'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['parent'])->toBe([__('posts.form.instagram.collaborators_max')]);
});

test('duplicate names in a long list fail as duplicates not as max', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['Host_One', 'host_one', 'HOST_ONE', 'a'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['parent'])->toBe([])
        ->and($errors['items']['platforms.0.meta.collaborators.1'] ?? [])
        ->toBe([__('posts.form.instagram.collaborators_duplicate')])
        ->and($errors['items']['platforms.0.meta.collaborators.2'] ?? [])
        ->toBe([__('posts.form.instagram.collaborators_duplicate')]);
});

test('a non-list value is left to the array rule instead of wiping the stored list', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    expect(runCollaboratorsMetaRule('@Host_One, host_one', ['social_account_id' => $account->id])['items'])
        ->toBe([])
        ->and(PostPlatformMetaRules::rules()['platforms.*.meta.collaborators'])
        ->toContain('array');
});

test('fails on a leading period username', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
    ]);

    $errors = runCollaboratorsMetaRule(['.user'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['items']['platforms.0.meta.collaborators.0'] ?? [])
        ->toBe([__('posts.form.instagram.collaborators_invalid')]);
});

test('skips stories because the merge drops their collaborators anyway', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['@TestUser', 'a', 'b', 'c', 'not valid!!'], [
        'social_account_id' => $account->id,
        'content_type' => ContentType::InstagramStory->value,
    ]);

    expect($errors['parent'])->toBe([])
        ->and($errors['items'])->toBe([]);
});

test('a stale post platform id falls back to the submitted account instead of skipping', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['not valid!!'], [
        'id' => '0198c3f1-1111-7abc-9def-000000000000',
        'social_account_id' => $account->id,
    ]);

    expect($errors['items']['platforms.0.meta.collaborators.0'] ?? [])
        ->toBe([__('posts.form.instagram.collaborators_invalid')]);
});

test('a disconnected account still validates against the stored platform column', function () {
    $workspace = Workspace::factory()->create();
    $post = Post::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => User::factory(),
    ]);
    $platform = PostPlatform::factory()->instagram()->create([
        'post_id' => $post->id,
        'social_account_id' => null,
    ]);

    expect(runCollaboratorsMetaRule(['not valid!!'], ['id' => $platform->id]))
        ->items->toBe(['platforms.0.meta.collaborators.0' => [__('posts.form.instagram.collaborators_invalid')]])
        ->and(runCollaboratorsMetaRule(['a', 'b', 'c', 'd'], ['id' => $platform->id]))
        ->parent->toBe([__('posts.form.instagram.collaborators_max')]);
});

test('an oversized list is rejected by size before it can flood the error bag', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->instagram()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $validator = Validator::make([
        'platforms' => [[
            'social_account_id' => $account->id,
            'meta' => ['collaborators' => array_fill(0, 50_000, '!!!bad')],
        ]],
    ], ['platforms' => ['sometimes', 'array']] + PostPlatformMetaRules::rules());

    expect($validator->fails())->toBeTrue()
        ->and($validator->errors()->messages())->toHaveCount(1)
        ->and($validator->errors()->keys())->toBe(['platforms.0.meta.collaborators']);
});

test('skips instagram constraints when the platform is tiktok', function () {
    $workspace = Workspace::factory()->create();
    $account = SocialAccount::factory()->tiktok()->create([
        'workspace_id' => $workspace->id,
        'username' => 'testuser',
    ]);

    $errors = runCollaboratorsMetaRule(['@TestUser', 'a', 'b', 'c', 'not valid!!'], [
        'social_account_id' => $account->id,
    ]);

    expect($errors['parent'])->toBe([])
        ->and($errors['items'])->toBe([]);
});
