<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\GatedFeature;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\ContentLimitException;
use App\Exceptions\Social\MissingPermissionException;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ChannelPermissions;
use App\Support\Social\PublishContentValidator;

test('a caption within the publishing limit passes', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($socialAccount)->create([
        'user_id' => $user->id,
        'content' => str_repeat('a', 100),
        'content_type' => ContentType::LinkedInPost,
    ]);

    expect(fn () => PublishContentValidator::validate($post))->not->toThrow(Exception::class);
});

test('a caption exceeding the publishing limit is refused', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $socialAccount = SocialAccount::factory()->linkedin()->create(['workspace_id' => $workspace->id]);
    $post = Post::factory()->forAccount($socialAccount)->create([
        'user_id' => $user->id,
        'content' => str_repeat('a', 4000),
        'content_type' => ContentType::LinkedInPost,
    ]);

    expect(fn () => PublishContentValidator::validate($post))
        ->toThrow(ContentLimitException::class, __('posts.errors.content_too_long', [
            'platform' => Platform::LinkedIn->label(),
            'max' => 3000,
            'provided' => 4000,
        ]));
});

test('an instagram story with a comment is blocked by its format when publishing with an old grant', function (Platform $platform, array $publishScopes) {
    config(["trypost.platforms.{$platform->value}.scopes" => ChannelPermissions::missingFrom([], $platform, GatedFeature::ThreadReplies)]);
    $account = SocialAccount::factory()->create(['platform' => $platform, 'scopes' => $publishScopes]);
    $post = Post::factory()->forAccount($account, ContentType::InstagramStory)->create([
        'meta' => ['thread_replies' => ['More details in the bio']],
    ]);

    expect(fn () => PublishContentValidator::validate($post))
        ->toThrow(ContentLimitException::class, __('posts.form.thread.instagram_story'));
})->with([
    'instagram login' => [Platform::Instagram, ['instagram_business_basic', 'instagram_business_content_publish']],
    'facebook login' => [Platform::InstagramFacebook, ['instagram_basic', 'pages_read_engagement', 'instagram_content_publish']],
]);

test('a missing instagram comment grant blocks the comment and allows publishing without it', function (Platform $platform, string $publish) {
    config(["trypost.platforms.{$platform->value}.scopes" => [$publish, ...ChannelPermissions::missingFrom([], $platform, GatedFeature::ThreadReplies)]]);
    $account = SocialAccount::factory()->create(['platform' => $platform, 'scopes' => [$publish]]);
    $post = Post::factory()->forAccount($account)->create([
        'content_type' => ContentType::InstagramFeed,
        'meta' => ['thread_replies' => ['Read more in the bio']],
    ]);

    expect(fn () => PublishContentValidator::validate($post))->toThrow(MissingPermissionException::class);

    config(["trypost.platforms.{$platform->value}.scopes" => [$publish]]);

    expect(fn () => PublishContentValidator::validate($post))
        ->toThrow(ContentLimitException::class, __('posts.form.thread.comment_unsupported'));

    $post->meta = [];

    expect(fn () => PublishContentValidator::validate($post))->not->toThrow(Exception::class);
})->with([
    'instagram login' => [Platform::Instagram, 'instagram_business_content_publish'],
    'facebook login' => [Platform::InstagramFacebook, 'instagram_content_publish'],
]);

test('a partial instagram grant missing a prerequisite cannot publish a first comment', function (Platform $platform, array $scopes) {
    config(["trypost.platforms.{$platform->value}.scopes" => [...$scopes, ...ChannelPermissions::missingFrom([], $platform, GatedFeature::ThreadReplies)]]);
    $account = SocialAccount::factory()->create(['platform' => $platform, 'scopes' => $scopes]);
    $post = Post::factory()->forAccount($account)->create(['meta' => ['thread_replies' => ['Read more in the bio']]]);

    expect(fn () => PublishContentValidator::validate($post))->toThrow(MissingPermissionException::class);

    $post->meta = [];
    expect(fn () => PublishContentValidator::validate($post))->not->toThrow(Exception::class);
})->with([
    'instagram basic' => [Platform::Instagram, ['instagram_business_content_publish', 'instagram_business_manage_comments']],
    'facebook basic' => [Platform::InstagramFacebook, ['instagram_content_publish', 'instagram_manage_comments', 'pages_read_engagement']],
    'facebook page engagement' => [Platform::InstagramFacebook, ['instagram_content_publish', 'instagram_manage_comments', 'instagram_basic']],
]);
