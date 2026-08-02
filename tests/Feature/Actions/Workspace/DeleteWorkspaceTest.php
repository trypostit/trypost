<?php

declare(strict_types=1);

use App\Actions\Workspace\DeleteWorkspace;
use App\Enums\UserWorkspace\Role;
use App\Jobs\PostHog\SyncAccountUsage;
use App\Models\Account;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Bus;

test('delete workspace dispatches SyncAccountUsage when PostHog is enabled', function () {
    config(['services.posthog.enabled' => true, 'services.posthog.api_key' => 'phc_test_key']);

    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    Bus::fake();

    DeleteWorkspace::execute($workspace);

    expect(Workspace::find($workspace->id))->toBeNull();

    Bus::assertDispatched(SyncAccountUsage::class, function ($job) use ($account) {
        return $job->accountId === (string) $account->id
            && $job->workspaceId === null;
    });
});

test('delete workspace does not dispatch SyncAccountUsage when PostHog is disabled', function () {
    config(['services.posthog.enabled' => false, 'services.posthog.api_key' => null]);

    $account = Account::factory()->create();
    $user = User::factory()->create(['account_id' => $account->id]);
    $account->update(['owner_id' => $user->id]);

    $workspace = Workspace::factory()->create([
        'account_id' => $account->id,
        'user_id' => $user->id,
    ]);
    $workspace->members()->attach($user->id, ['role' => Role::Member->value]);
    $user->update(['current_workspace_id' => $workspace->id]);

    Bus::fake();

    DeleteWorkspace::execute($workspace);

    expect(Workspace::find($workspace->id))->toBeNull();

    Bus::assertNotDispatched(SyncAccountUsage::class);
});
