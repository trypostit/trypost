<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Exceptions\SocialAccount\NetworkAlreadyConnectedException;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.allow_multiple_social_accounts', false);
    $this->workspace = Workspace::factory()->create();
});

test('blocks a second account of the same network', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('collapses platform variants into one network', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'li-profile',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedInPage,
        'platform_user_id' => 'li-page',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('allows different networks in the same workspace', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $x = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-a',
    ]);

    expect($x->exists)->toBeTrue();
});

test('allows the same network in different workspaces', function () {
    $other = Workspace::factory()->create();

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $other->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect($second->exists)->toBeTrue();
});

test('reconnecting the same account via updateOrCreate is allowed', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
        'username' => 'old',
    ]);

    $this->workspace->socialAccounts()->updateOrCreate(
        ['platform' => Platform::Instagram->value, 'platform_user_id' => 'ig-a'],
        ['username' => 'new', 'status' => Status::Connected],
    );

    expect($this->workspace->socialAccounts()->count())->toBe(1)
        ->and($this->workspace->socialAccounts()->first()->username)->toBe('new');
});

test('allowing multiple social accounts bypasses the one-per-network rule', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]);

    expect($second->exists)->toBeTrue();
});

test('multiple social accounts can be enabled without self-hosted mode', function () {
    config()->set('trypost.self_hosted', false);
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]);

    expect($second->exists)->toBeTrue();
});

test('self-hosted still enforces one-per-network when multiple social accounts are disabled', function () {
    config()->set('trypost.self_hosted', true);
    config()->set('trypost.allow_multiple_social_accounts', false);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-b',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('occupiesNetwork is true when the workspace already has that network', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(SocialAccount::occupiesNetwork((string) $this->workspace->id, Platform::Instagram))->toBeTrue()
        ->and(SocialAccount::occupiesNetwork((string) $this->workspace->id, Platform::X))->toBeFalse();
});

test('blocks a same-id account connected via a different network variant', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig-id',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::InstagramFacebook,
        'platform_user_id' => 'shared-ig-id',
    ]))->toThrow(NetworkAlreadyConnectedException::class);
});

test('the same workspace platform identity cannot be stored twice', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('connectIdentity refuses to repoint the reconnect target at another identity', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
        'username' => 'old',
    ]);

    expect(fn () => SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-b',
        ['username' => 'new', 'status' => Status::Connected],
        $account,
    ))->toThrow(NetworkAlreadyConnectedException::class);

    expect($account->fresh()->platform_user_id)->toBe('ig-a')
        ->and($account->fresh()->username)->toBe('old')
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('connectIdentity keeps posts on the card when a stray identity is authorized', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-brand',
        'username' => 'brand',
    ]);

    expect(fn () => SocialAccount::connectIdentity(
        $this->workspace,
        Platform::X,
        'x-personal',
        [
            'username' => 'personal',
            'status' => Status::Connected,
            'access_token' => 'personal-token',
        ],
        $account,
    ))->toThrow(NetworkAlreadyConnectedException::class);

    expect($account->fresh()->platform_user_id)->toBe('x-brand')
        ->and($account->fresh()->username)->toBe('brand')
        ->and($this->workspace->socialAccounts()->where('platform_user_id', 'x-personal')->exists())->toBeFalse();
});

test('connectIdentity still reconnects the same identity across a network variant', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'li-same',
        'username' => 'old',
    ]);

    $updated = SocialAccount::connectIdentity(
        $this->workspace,
        Platform::LinkedInPage,
        'li-same',
        ['username' => 'new', 'status' => Status::Connected],
        $account,
    );

    expect($updated->id)->toBe($account->id)
        ->and($updated->platform)->toBe(Platform::LinkedInPage)
        ->and($updated->username)->toBe('new')
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('connectIdentity reconnect throws when the new identity is already taken', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-keep',
    ]);

    $move = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-move',
    ]);

    expect(fn () => SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-keep',
        ['username' => 'taken', 'status' => Status::Connected],
        $move,
    ))->toThrow(NetworkAlreadyConnectedException::class);
});

test('connectIdentity ignores a reconnect target from another network', function () {
    $facebook = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Facebook,
        'platform_user_id' => 'page-1',
    ]);

    $instagram = SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-new',
        [
            'username' => 'fresh',
            'status' => Status::Connected,
            'access_token' => 'ig-token',
        ],
        $facebook,
    );

    expect($instagram->id)->not->toBe($facebook->id)
        ->and($instagram->platform)->toBe(Platform::Instagram)
        ->and($facebook->fresh()->platform)->toBe(Platform::Facebook)
        ->and($this->workspace->socialAccounts()->count())->toBe(2);
});

test('the observer leaves a missing platform to the database instead of a type error', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-a',
    ]);

    $account = new SocialAccount([
        'workspace_id' => $this->workspace->id,
        'platform_user_id' => 'no-platform',
    ]);

    expect(fn () => $account->save())
        ->toThrow(QueryException::class)
        ->and(fn () => $account->save())->not->toThrow(TypeError::class);
});

test('connectIdentity serializes connects on the same network', function () {
    $lock = Cache::lock("social_connect:{$this->workspace->id}:instagram", 10);

    expect($lock->get())->toBeTrue();

    try {
        try {
            SocialAccount::connectIdentity(
                $this->workspace,
                Platform::Instagram,
                'ig-a',
                ['username' => 'blocked', 'status' => Status::Connected],
            );

            $this->fail('A busy network lock should reject the connect.');
        } catch (NetworkAlreadyConnectedException $e) {
            expect($e->messageKey)->toBe('busy');
        }

        expect($this->workspace->socialAccounts()->count())->toBe(0);
    } finally {
        $lock->release();
    }
});

test('connectIdentity does not serialize connects on different networks', function () {
    $lock = Cache::lock("social_connect:{$this->workspace->id}:instagram", 10);

    expect($lock->get())->toBeTrue();

    try {
        $account = SocialAccount::connectIdentity(
            $this->workspace,
            Platform::X,
            'x-a',
            ['username' => 'free', 'status' => Status::Connected, 'access_token' => 'x-token'],
        );

        expect($account->exists)->toBeTrue();
    } finally {
        $lock->release();
    }
});

test('connectIdentity releases the lock so the next connect proceeds', function () {
    SocialAccount::connectIdentity(
        $this->workspace,
        Platform::X,
        'x-a',
        ['username' => 'first', 'status' => Status::Connected, 'access_token' => 'x-token'],
    );

    $second = SocialAccount::connectIdentity(
        $this->workspace,
        Platform::X,
        'x-a',
        ['username' => 'second', 'status' => Status::Connected, 'access_token' => 'x-token-2'],
    );

    expect($second->username)->toBe('second')
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('reconnecting through the other variant moves pending targets onto the new platform', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig-id',
        'scopes' => ['instagram_business_content_publish'],
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $pending = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'content_type' => ContentType::InstagramReel,
        'status' => PostPlatformStatus::Pending,
    ]);

    SocialAccount::connectIdentity(
        $this->workspace,
        Platform::InstagramFacebook,
        'shared-ig-id',
        ['username' => 'brand', 'scopes' => ['instagram_content_publish'], 'status' => Status::Connected],
        $account,
    );

    expect($account->fresh()->platform)->toBe(Platform::InstagramFacebook)
        ->and($pending->fresh()->platform)->toBe(Platform::InstagramFacebook)
        ->and($pending->fresh()->content_type)->toBe(ContentType::InstagramReel)
        ->and(array_diff(
            $pending->fresh()->platform->requiredPublishScopes(),
            $account->fresh()->scopes,
        ))->toBe([]);
});

test('a variant move leaves a published target on the platform it really published to', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig-id',
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $published = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'status' => PostPlatformStatus::Published,
    ]);

    SocialAccount::connectIdentity(
        $this->workspace,
        Platform::InstagramFacebook,
        'shared-ig-id',
        ['username' => 'brand', 'status' => Status::Connected],
        $account,
    );

    expect($published->fresh()->platform)->toBe(Platform::Instagram);
});

test('a variant move resets a content type the new platform cannot publish', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
        'platform_user_id' => 'li-same',
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $pending = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::LinkedIn->value,
        'content_type' => ContentType::LinkedInPost,
        'status' => PostPlatformStatus::Pending,
    ]);

    SocialAccount::connectIdentity(
        $this->workspace,
        Platform::LinkedInPage,
        'li-same',
        ['username' => 'page', 'status' => Status::Connected],
        $account,
    );

    expect($pending->fresh()->platform)->toBe(Platform::LinkedInPage)
        ->and($pending->fresh()->content_type)->toBe(ContentType::LinkedInPagePost);
});

test('reconnecting the same variant leaves pending targets untouched', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'ig-same',
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $pending = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'content_type' => ContentType::InstagramStory,
        'status' => PostPlatformStatus::Pending,
    ]);

    $before = $pending->updated_at;

    SocialAccount::connectIdentity(
        $this->workspace,
        Platform::Instagram,
        'ig-same',
        ['username' => 'fresh', 'status' => Status::Connected],
        $account,
    );

    expect($pending->fresh()->platform)->toBe(Platform::Instagram)
        ->and($pending->fresh()->content_type)->toBe(ContentType::InstagramStory)
        ->and($pending->fresh()->updated_at->equalTo($before))->toBeTrue();
});

test('a variant move carries a retrying target, which still has a publish ahead of it', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig-id',
        'scopes' => ['instagram_business_content_publish'],
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $retrying = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'status' => PostPlatformStatus::Retrying,
    ]);

    SocialAccount::connectIdentity(
        $this->workspace,
        Platform::InstagramFacebook,
        'shared-ig-id',
        ['username' => 'brand', 'scopes' => ['instagram_content_publish'], 'status' => Status::Connected],
        $account,
    );

    expect($retrying->fresh()->platform)->toBe(Platform::InstagramFacebook)
        ->and(array_diff(
            $retrying->fresh()->platform->requiredPublishScopes(),
            $account->fresh()->scopes,
        ))->toBe([]);
});

test('a variant move leaves a failed target on the platform it failed against', function () {
    $account = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Instagram,
        'platform_user_id' => 'shared-ig-id',
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $failed = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Instagram->value,
        'status' => PostPlatformStatus::Failed,
    ]);

    SocialAccount::connectIdentity(
        $this->workspace,
        Platform::InstagramFacebook,
        'shared-ig-id',
        ['username' => 'brand', 'status' => Status::Connected],
        $account,
    );

    expect($failed->fresh()->platform)->toBe(Platform::Instagram);
});
