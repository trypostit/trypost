<?php

declare(strict_types=1);

use App\Console\Commands\ProcessScheduledPosts;
use App\Enums\Activity\LogName;
use App\Enums\Post\Status as PostStatus;
use App\Jobs\PublishPost;
use App\Models\Activity;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    $this->admin = admin();
});

function adminDueScheduledPost(Workspace $workspace, User $author): Post
{
    $channel = SocialAccount::factory()->create(['workspace_id' => $workspace->id]);

    return Post::factory()->forAccount($channel)->create([
        'user_id' => $author->id,
        'status' => PostStatus::Scheduled,
        'scheduled_at' => now()->subMinute(),
    ]);
}

test('blocking a user stores the reason, drops their sessions and records it', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    DB::table('sessions')->insert([
        'id' => 'session-of-the-user',
        'user_id' => $user->id,
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $user), ['reason' => 'Spam'])
        ->assertRedirect();

    $user->refresh();

    expect($user->isBlocked())->toBeTrue()
        ->and($user->blocked_reason)->toBe('Spam')
        ->and(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Activity::query()->inLog(LogName::Admin)->forSubject($user)->sole()->event)->toBe('user.blocked');
});

test('blocking under another session driver leaves the sessions table alone and still blocks', function () {
    config(['session.driver' => 'array']);
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'kept-row', 'user_id' => $user->id, 'payload' => '', 'last_activity' => now()->timestamp]);

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $user), ['reason' => 'Spam'])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->isBlocked())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'kept-row')->exists())->toBeTrue();
});

test('the block reason is trimmed and holds 500 characters at most', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $user), ['reason' => str_repeat('a', 501)])
        ->assertSessionHasErrors('reason');

    expect($user->fresh()->isBlocked())->toBeFalse();

    $reason = str_repeat('a', 500);

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $user), ['reason' => "  {$reason}  "])
        ->assertSessionHasNoErrors();

    expect($user->fresh()->blocked_reason)->toBe($reason);
});

test('blocking needs a reason', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $user), ['reason' => ''])
        ->assertSessionHasErrors('reason');

    expect($user->fresh()->isBlocked())->toBeFalse();
});

test('an admin cannot block themselves or another admin', function () {
    config()->set('trypost.admin.emails', [$this->admin->email, 'other@trypost.test']);
    $otherAdmin = User::factory()->create(['email' => 'other@trypost.test']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $this->admin), ['reason' => 'Test'])
        ->assertSessionHasErrors(['user' => 'A backoffice admin cannot be blocked.']);

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $otherAdmin), ['reason' => 'Test'])
        ->assertSessionHasErrors(['user' => 'A backoffice admin cannot be blocked.']);

    expect($this->admin->fresh()->isBlocked())->toBeFalse()
        ->and($otherAdmin->fresh()->isBlocked())->toBeFalse();
});

test('unblocking clears the block and records it', function () {
    $user = User::factory()->blocked()->create();

    $this->actingAs($this->admin)
        ->delete(route('admin.users.block.destroy', $user))
        ->assertRedirect();

    expect($user->fresh()->isBlocked())->toBeFalse()
        ->and(Activity::query()->forSubject($user)->sole()->event)->toBe('user.unblocked');
});

test('blocking a blocked user keeps the first block and records nothing more', function () {
    $user = User::factory()->blocked('First')->create();
    $blockedAt = $user->blocked_at;

    $this->actingAs($this->admin)
        ->post(route('admin.users.block.store', $user), ['reason' => 'Second'])
        ->assertRedirect();

    $user->refresh();

    expect($user->blocked_reason)->toBe('First')
        ->and($user->blocked_at->equalTo($blockedAt))->toBeTrue()
        ->and(Activity::query()->forSubject($user)->exists())->toBeFalse();
});

test('unblocking a user who is not blocked records nothing', function () {
    $user = User::factory()->create();

    $this->actingAs($this->admin)
        ->delete(route('admin.users.block.destroy', $user))
        ->assertRedirect();

    expect(Activity::query()->forSubject($user)->exists())->toBeFalse();
});

test('a blocked user cannot log in with a password', function () {
    $user = User::factory()->blocked()->create(['password' => 'password']);

    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertSessionHasErrors(['email' => __('auth.blocked')]);

    $this->assertGuest();
});

test('a signed-in user who gets blocked is logged out on the next request', function () {
    $user = workspaceOwner(['blocked_at' => now(), 'blocked_reason' => 'Abuse']);

    $this->actingAs($user)
        ->get(route('app.calendar'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => __('auth.blocked')]);

    $this->assertGuest();
});

test('a blocked user cannot use their API key', function () {
    $result = createApiTestToken();
    $result['user']->forceFill(['blocked_at' => now()])->save();

    $this->withHeaders(['Authorization' => "Bearer {$result['plain_token']}"])
        ->getJson(route('api.labels.index'))
        ->assertForbidden();
});

test('a blocked owner keeps their scheduled posts from publishing until unblocked', function () {
    Queue::fake();
    $owner = workspaceOwner();
    $post = adminDueScheduledPost($owner->currentWorkspace, $owner);
    $owner->forceFill(['blocked_at' => now()])->save();

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertNotPushed(PublishPost::class);
    expect($post->fresh()->status)->toBe(PostStatus::Scheduled);

    $owner->forceFill(['blocked_at' => null])->save();
    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class, fn (PublishPost $job): bool => $job->post->is($post));
});

test('a blocked member does not stop the owner\'s posts', function () {
    Queue::fake();
    $owner = workspaceOwner();
    $member = User::factory()->blocked()->create();
    $workspace = $owner->currentWorkspace;
    $workspace->members()->attach($member->id, membershipPivot('member'));
    adminDueScheduledPost($workspace, $member);

    $this->artisan(ProcessScheduledPosts::class)->assertSuccessful();

    Queue::assertPushed(PublishPost::class);
});

test('the users list filters blocked users and shows the block', function () {
    $blocked = User::factory()->blocked()->create();
    User::factory()->create();

    $this->actingAs($this->admin)
        ->get(route('admin.users.index', ['status' => 'blocked']))
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('users.data', 1)
            ->where('users.data.0.id', $blocked->id)
            ->where('users.data.0.blocked_reason', 'Abuse')
            ->where('filters.status', 'blocked'),
        );
});
