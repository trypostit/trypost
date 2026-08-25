<?php

declare(strict_types=1);

use App\Enums\PostPlatform\Status as PostPlatformStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Automation;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Schema;

/**
 * Installs that predate the unique index could hold the same identity twice, so
 * the migration has to collapse them before the index can be created.
 */
beforeEach(function () {
    config()->set('trypost.allow_multiple_social_accounts', true);

    $this->migration = require database_path(
        'migrations/2026_08_21_130941_add_workspace_platform_identity_unique_to_social_accounts_table.php',
    );

    Schema::table('social_accounts', function (Blueprint $table) {
        $table->dropUnique('social_accounts_workspace_platform_identity_unique');
    });

    $this->workspace = Workspace::factory()->create();
});

test('it collapses duplicate identities and keeps the newest row', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'username' => 'older',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'username' => 'newer',
        'created_at' => now(),
    ]);

    $this->migration->up();

    expect(SocialAccount::whereKey($newer->id)->exists())->toBeTrue()
        ->and(SocialAccount::whereKey($older->id)->exists())->toBeFalse()
        ->and($this->workspace->socialAccounts()->count())->toBe(1);
});

test('it moves posts from the dropped duplicate onto the surviving account', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $platform = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $older->id,
        'platform' => Platform::Pinterest,
    ]);

    $this->migration->up();

    expect($platform->fresh()->social_account_id)->toBe($newer->id);
});

test('it leaves a post with a single target when both duplicates were selected', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    foreach ([$older, $newer] as $account) {
        PostPlatform::factory()->create([
            'post_id' => $post->id,
            'social_account_id' => $account->id,
            'platform' => Platform::Pinterest,
        ]);
    }

    $this->migration->up();

    expect(PostPlatform::where('post_id', $post->id)->count())->toBe(1)
        ->and(PostPlatform::where('post_id', $post->id)->first()->social_account_id)->toBe($newer->id);
});

test('it never deletes a published row when collapsing repeated post targets', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    // Both duplicates were enabled, so the post really did go out twice and
    // each row holds the platform_post_id for a live post on the network.
    $rows = collect([$older, $newer])->map(fn (SocialAccount $account) => PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $account->id,
        'platform' => Platform::Pinterest,
        'status' => PostPlatformStatus::Published,
    ]));

    $this->migration->up();

    expect(PostPlatform::where('post_id', $post->id)->pluck('id')->sort()->values()->all())
        ->toBe($rows->pluck('id')->sort()->values()->all());
});

test('it keeps the enabled row when collapsing repeated post targets', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    // SyncPostPlatforms seeds a disabled row for every account, so the enabled
    // one is not necessarily the newest.
    $enabled = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $older->id,
        'platform' => Platform::Pinterest,
        'status' => PostPlatformStatus::Pending,
        'enabled' => true,
    ]);

    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $newer->id,
        'platform' => Platform::Pinterest,
        'status' => PostPlatformStatus::Pending,
        'enabled' => false,
    ]);

    $this->migration->up();

    expect(PostPlatform::where('post_id', $post->id)->pluck('id')->all())->toBe([$enabled->id]);
});

test('it leaves distinct identities untouched', function () {
    $first = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
    ]);

    $second = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-2',
    ]);

    $this->migration->up();

    expect(SocialAccount::whereKey($first->id)->exists())->toBeTrue()
        ->and(SocialAccount::whereKey($second->id)->exists())->toBeTrue();
});

test('it restores the unique index so duplicates cannot come back', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $this->migration->up();

    expect(fn () => SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

test('it repoints automation nodes at the surviving account', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $automation = Automation::factory()->for($this->workspace)->create([
        'nodes' => [
            [
                'id' => 'node-1',
                'type' => 'generate',
                'config' => [
                    'accounts' => [
                        ['social_account_id' => $older->id, 'content_type' => 'pinterest_pin'],
                    ],
                ],
            ],
        ],
    ]);

    $this->migration->up();

    expect(data_get($automation->fresh()->nodes, '0.config.accounts.0.social_account_id'))->toBe($newer->id);
});

test('it collapses automation targets that the merge turned into duplicates', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $automation = Automation::factory()->for($this->workspace)->create([
        'nodes' => [
            [
                'id' => 'node-1',
                'type' => 'generate',
                'config' => [
                    'accounts' => [
                        ['social_account_id' => $older->id, 'content_type' => 'pinterest_pin'],
                        ['social_account_id' => $newer->id, 'content_type' => 'pinterest_pin'],
                    ],
                ],
            ],
        ],
    ]);

    $this->migration->up();

    expect(data_get($automation->fresh()->nodes, '0.config.accounts'))->toHaveCount(1)
        ->and(data_get($automation->fresh()->nodes, '0.config.accounts.0.social_account_id'))->toBe($newer->id);
});

test('it repoints the legacy social_account_ids shape too', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $automation = Automation::factory()->for($this->workspace)->create([
        'nodes' => [
            [
                'id' => 'node-1',
                'type' => 'generate',
                'config' => ['social_account_ids' => [$older->id, $newer->id]],
            ],
        ],
    ]);

    $this->migration->up();

    expect(data_get($automation->fresh()->nodes, '0.config.social_account_ids'))->toBe([$newer->id]);
});

test('it drops every unpublished repeat once the post already published there', function () {
    $older = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    $newer = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $post = Post::factory()->create(['workspace_id' => $this->workspace->id]);

    $published = PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $older->id,
        'platform' => Platform::Pinterest,
        'status' => PostPlatformStatus::Published,
    ]);

    // Enabled and pending against the duplicate: a republish would deliver the
    // same content to the same identity a second time.
    PostPlatform::factory()->create([
        'post_id' => $post->id,
        'social_account_id' => $newer->id,
        'platform' => Platform::Pinterest,
        'status' => PostPlatformStatus::Pending,
        'enabled' => true,
    ]);

    $this->migration->up();

    expect(PostPlatform::where('post_id', $post->id)->pluck('id')->all())->toBe([$published->id]);
});

test('it leaves automations the merge never touched alone', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now()->subDay(),
    ]);

    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::Pinterest,
        'platform_user_id' => 'pin-1',
        'created_at' => now(),
    ]);

    $untouched = SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
        'platform_user_id' => 'x-1',
    ]);

    $nodes = [
        [
            'id' => 'node-1',
            'type' => 'generate',
            'config' => [
                'accounts' => [
                    ['social_account_id' => $untouched->id, 'content_type' => 'x_post'],
                    ['social_account_id' => $untouched->id, 'content_type' => 'x_thread'],
                ],
            ],
        ],
    ];

    $automation = Automation::factory()->for($this->workspace)->create(['nodes' => $nodes]);

    $this->migration->up();

    expect($automation->fresh()->nodes)->toBe($nodes);
});
