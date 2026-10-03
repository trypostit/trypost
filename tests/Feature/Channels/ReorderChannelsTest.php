<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->user->account_id,
        'user_id' => $this->user->id,
    ]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

function reorderChannelsMember(Workspace $workspace, string $role): User
{
    $member = User::factory()->create(['account_id' => $workspace->account_id]);
    $workspace->members()->attach($member->id, membershipPivot($role));
    $member->update(['current_workspace_id' => $workspace->id]);

    return $member->fresh();
}

/**
 * @return list<SocialAccount>
 */
function reorderChannelsAccounts(Workspace $workspace, int $count = 3): array
{
    return collect(range(1, $count))
        ->map(fn (int $index): SocialAccount => SocialAccount::factory()->linkedin()->create([
            'workspace_id' => $workspace->id,
            'created_at' => now()->subDays(10 - $index),
        ]))
        ->all();
}

function connectChannelForOrder(Workspace $workspace, string $platformUserId, ?SocialAccount $reconnect = null): SocialAccount
{
    return SocialAccount::connectIdentity($workspace, Platform::LinkedIn, $platformUserId, [
        'username' => "user-{$platformUserId}",
        'display_name' => 'Reordered',
        'access_token' => 'token',
        'status' => 'connected',
    ], $reconnect);
}

test('reorder persists the new order and both the sidebar and settings list follow it', function () {
    [$a, $b, $c] = reorderChannelsAccounts($this->workspace);

    $this->actingAs($this->user)
        ->put(route('app.channels.reorder'), ['social_account_ids' => [$c->id, $a->id, $b->id]])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($this->workspace->socialAccounts()->pluck('id')->all())->toBe([$c->id, $a->id, $b->id])
        ->and([$c->fresh()->position, $a->fresh()->position, $b->fresh()->position])->toBe([0, 1, 2]);

    $this->actingAs($this->user)
        ->get(route('app.workspace.channels'))
        ->assertInertia(fn ($page) => $page
            ->where('channels.0.id', $c->id)
            ->where('channels.1.id', $a->id)
            ->where('channels.2.id', $b->id)
            ->where('connectedChannels.0.id', $c->id)
            ->where('connectedChannels.1.id', $a->id)
            ->where('connectedChannels.2.id', $b->id)
        );
});

test('reorder rejects a stale id list and changes nothing', function (string $case) {
    [$a, $b] = reorderChannelsAccounts($this->workspace, 2);
    $foreign = SocialAccount::factory()->linkedin()->create();

    $ids = match ($case) {
        'missing' => [$b->id],
        'extra' => [$b->id, $a->id, $foreign->id],
        'foreign' => [$b->id, $foreign->id],
        'duplicate' => [$a->id, $a->id],
        'unknown' => [$b->id, '0199a000-0000-7000-8000-000000000000'],
    };

    $this->actingAs($this->user)
        ->putJson(route('app.channels.reorder'), ['social_account_ids' => $ids])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['social_account_ids' => __('channels.reorder.stale')]);

    expect($this->workspace->socialAccounts()->pluck('id')->all())->toBe([$a->id, $b->id])
        ->and($foreign->fresh()->position)->toBe(0);
})->with(['missing', 'extra', 'foreign', 'duplicate', 'unknown']);

test('reorder requires a list of ids', function () {
    reorderChannelsAccounts($this->workspace, 1);

    $this->actingAs($this->user)
        ->putJson(route('app.channels.reorder'), ['social_account_ids' => ['not-a-uuid']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('social_account_ids.0');

    $this->actingAs($this->user)
        ->putJson(route('app.channels.reorder'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('social_account_ids');
});

test('only members who manage channels can reorder them', function (string $role) {
    [$a, $b] = reorderChannelsAccounts($this->workspace, 2);

    $this->actingAs(reorderChannelsMember($this->workspace, $role))
        ->put(route('app.channels.reorder'), ['social_account_ids' => [$b->id, $a->id]])
        ->assertForbidden();

    expect($this->workspace->socialAccounts()->pluck('id')->all())->toBe([$a->id, $b->id]);
})->with(['member', 'approval']);

test('a new channel is appended after the workspace channels', function () {
    [$a, $b] = reorderChannelsAccounts($this->workspace, 2);
    $b->forceFill(['position' => 7])->saveQuietly();
    SocialAccount::factory()->linkedin()->create(['position' => 50]);

    $connected = connectChannelForOrder($this->workspace, 'new-identity');
    $otherWorkspaceFirst = SocialAccount::factory()->linkedin()->create();

    expect($a->fresh()->position)->toBe(0)
        ->and($connected->fresh()->position)->toBe(8)
        ->and($otherWorkspaceFirst->fresh()->position)->toBe(0)
        ->and($this->workspace->socialAccounts()->pluck('id')->last())->toBe($connected->id);
});

test('reconnecting a channel keeps its position', function () {
    $first = connectChannelForOrder($this->workspace, 'identity-1');
    $second = connectChannelForOrder($this->workspace, 'identity-2');

    $this->actingAs($this->user)
        ->put(route('app.channels.reorder'), ['social_account_ids' => [$second->id, $first->id]])
        ->assertSessionHasNoErrors();

    connectChannelForOrder($this->workspace, 'identity-1', $first->fresh());
    connectChannelForOrder($this->workspace, 'identity-2');

    expect($this->workspace->socialAccounts()->pluck('id')->all())->toBe([$second->id, $first->id])
        ->and($first->fresh()->position)->toBe(1);
});

test('the global scope orders every list by position, then created_at and id', function () {
    [$a, $b, $c] = reorderChannelsAccounts($this->workspace);
    $a->forceFill(['position' => 2])->saveQuietly();
    $b->forceFill(['position' => 0])->saveQuietly();
    $c->forceFill(['position' => 0, 'created_at' => $b->created_at->addMinute()])->saveQuietly();

    expect(SocialAccount::query()->where('workspace_id', $this->workspace->id)->pluck('id')->all())->toBe([$b->id, $c->id, $a->id])
        ->and($this->workspace->socialAccounts->pluck('id')->all())->toBe([$b->id, $c->id, $a->id])
        ->and(Workspace::query()->with('socialAccounts')->find($this->workspace->id)->socialAccounts->pluck('id')->all())->toBe([$b->id, $c->id, $a->id])
        ->and($this->workspace->socialAccounts()->latest()->pluck('id')->first())->toBe($c->id)
        ->and($this->workspace->socialAccounts()->withoutGlobalScopes()->orderBy('created_at')->pluck('id')->all())->toBe([$a->id, $b->id, $c->id]);
});

test('the global scope does not break aggregates, grouping, distinct or relation sub-queries', function () {
    [$a, $b] = reorderChannelsAccounts($this->workspace, 2);
    $x = SocialAccount::factory()->x()->create(['workspace_id' => $this->workspace->id]);
    $post = Post::factory()->scheduled()->create(['workspace_id' => $this->workspace->id]);
    PostPlatform::factory()->create(['post_id' => $post->id, 'social_account_id' => $x->id]);

    $accounts = fn () => SocialAccount::query()->where('workspace_id', $this->workspace->id);

    $grouped = $accounts()
        ->select('platform', DB::raw('count(*) as total'))
        ->groupBy('platform')
        ->get()
        ->mapWithKeys(fn (SocialAccount $row): array => [$row->platform->value => (int) $row->getAttribute('total')])
        ->all();

    expect($accounts()->count())->toBe(3)
        ->and((int) $accounts()->max('position'))->toBe(2)
        ->and((int) $accounts()->sum('position'))->toBe(3)
        ->and($accounts()->exists())->toBeTrue()
        ->and($accounts()->paginate(2)->total())->toBe(3)
        ->and($grouped)->toEqual([Platform::LinkedIn->value => 2, Platform::X->value => 1])
        ->and($accounts()->distinct()->pluck('platform')->map->value->sort()->values()->all())->toBe([Platform::LinkedIn->value, Platform::X->value])
        ->and(Workspace::query()->withCount('socialAccounts')->find($this->workspace->id)->social_accounts_count)->toBe(3)
        ->and(Workspace::query()->has('socialAccounts', '>=', 3)->whereKey($this->workspace->id)->exists())->toBeTrue()
        ->and(PostPlatform::query()->whereHas('socialAccount', fn ($query) => $query->where('workspace_id', $this->workspace->id))->count())->toBe(1)
        ->and(PostPlatform::query()->whereIn('social_account_id', $accounts()->select('id'))->count())->toBe(1)
        ->and($accounts()->whereKey([$a->id, $b->id])->pluck('id')->all())->toBe([$a->id, $b->id]);
});
