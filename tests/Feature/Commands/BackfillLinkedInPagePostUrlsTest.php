<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->socialAccount = SocialAccount::factory()->linkedinPage()->create([
        'workspace_id' => $this->workspace->id,
        'username' => 'testcompany',
    ]);
    $this->post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);
});

test('it corrects broken linkedin page post urls', function () {
    $broken = PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedInPage,
        'platform_post_id' => 'urn:li:share:1234567890',
        'platform_url' => 'https://www.linkedin.com/company/testcompany/posts/',
    ]);

    $this->artisan('social:backfill-linkedin-page-post-urls')->assertSuccessful();

    $broken->refresh();
    expect($broken->platform_url)->toBe('https://www.linkedin.com/feed/update/urn:li:share:1234567890');
});

test('it leaves already-correct linkedin page urls untouched', function () {
    $correct = PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedInPage,
        'platform_post_id' => 'urn:li:share:1234567890',
        'platform_url' => 'https://www.linkedin.com/feed/update/urn:li:share:1234567890',
    ]);

    $this->artisan('social:backfill-linkedin-page-post-urls')->assertSuccessful();

    $correct->refresh();
    expect($correct->platform_url)->toBe('https://www.linkedin.com/feed/update/urn:li:share:1234567890');
});

test('it does not touch other platforms with a similarly shaped url', function () {
    $other = PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => SocialAccount::factory()->create([
            'workspace_id' => $this->workspace->id,
            'platform' => Platform::LinkedIn,
        ]),
        'platform' => Platform::LinkedIn,
        'platform_post_id' => 'urn:li:share:9999999999',
        'platform_url' => 'https://www.linkedin.com/company/testcompany/posts/',
    ]);

    $this->artisan('social:backfill-linkedin-page-post-urls')->assertSuccessful();

    $other->refresh();
    expect($other->platform_url)->toBe('https://www.linkedin.com/company/testcompany/posts/');
});

test('dry run previews without writing changes', function () {
    $broken = PostPlatform::factory()->published()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedInPage,
        'platform_post_id' => 'urn:li:share:1234567890',
        'platform_url' => 'https://www.linkedin.com/company/testcompany/posts/',
    ]);

    $this->artisan('social:backfill-linkedin-page-post-urls', ['--dry-run' => true])->assertSuccessful();

    $broken->refresh();
    expect($broken->platform_url)->toBe('https://www.linkedin.com/company/testcompany/posts/');
});

test('it ignores linkedin page rows that are not published', function () {
    $failed = PostPlatform::factory()->create([
        'post_id' => $this->post->id,
        'social_account_id' => $this->socialAccount->id,
        'platform' => Platform::LinkedInPage,
        'status' => Status::Failed,
        'platform_post_id' => 'urn:li:share:1234567890',
        'platform_url' => 'https://www.linkedin.com/company/testcompany/posts/',
    ]);

    $this->artisan('social:backfill-linkedin-page-post-urls')->assertSuccessful();

    $failed->refresh();
    expect($failed->platform_url)->toBe('https://www.linkedin.com/company/testcompany/posts/');
});
