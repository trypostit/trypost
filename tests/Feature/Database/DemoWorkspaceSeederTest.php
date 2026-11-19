<?php

declare(strict_types=1);

use App\Enums\Post\Status as PostStatus;
use App\Models\Account;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoWorkspaceSeeder;

test('the demo workspace has app access and a post in every board column', function () {
    $this->seed(DemoWorkspaceSeeder::class);

    $user = User::query()->where('email', DemoWorkspaceSeeder::EMAIL)->firstOrFail();

    expect($user->account->hasAppAccess())->toBeTrue()
        ->and($user->currentWorkspace->socialAccounts()->count())->toBe(10)
        ->and(Post::query()->where('workspace_id', $user->current_workspace_id)->distinct()->pluck('status')->all())
        ->toEqualCanonicalizing([PostStatus::Draft, PostStatus::PendingApproval, PostStatus::Scheduled, PostStatus::Published, PostStatus::Failed]);
});

test('seeding again replaces the demo workspace instead of adding a second one', function () {
    $this->seed(DemoWorkspaceSeeder::class);
    $this->seed(DemoWorkspaceSeeder::class);

    expect(User::query()->where('email', DemoWorkspaceSeeder::EMAIL)->count())->toBe(1)
        ->and(Workspace::query()->count())->toBe(1);
});

test('forget removes the demo user, its account and its posts, and nothing else', function () {
    $other = User::factory()->create();
    $this->seed(DemoWorkspaceSeeder::class);

    DemoWorkspaceSeeder::forget();

    expect(User::query()->pluck('id')->all())->toBe([$other->id])
        ->and(Account::query()->pluck('id')->all())->toBe([$other->account_id])
        ->and(Post::query()->count())->toBe(0);
});
