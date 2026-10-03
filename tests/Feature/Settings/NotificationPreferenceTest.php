<?php

declare(strict_types=1);

use App\Enums\Notification\Type;
use App\Jobs\SendNotification;
use App\Mail\PostPublished;
use App\Mail\PostPublishFailed;
use App\Models\NotificationPreference;
use App\Models\Post;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->user = User::factory()->create([]);
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('member'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('notification preferences page requires authentication', function () {
    $response = $this->get(route('app.notifications.preferences'));

    $response->assertRedirect(route('login'));
});

test('notification preferences page renders', function () {
    $response = $this->actingAs($this->user)->get(route('app.notifications.preferences'));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('settings/profile/Notifications')
        ->has('preferences')
    );
});

test('notification preferences are created with defaults on first visit', function () {
    expect(NotificationPreference::where('user_id', $this->user->id)->count())->toBe(0);

    $this->actingAs($this->user)->get(route('app.notifications.preferences'));

    $preference = NotificationPreference::where('user_id', $this->user->id)->first();
    expect($preference)->not->toBeNull();
    expect($preference->post_published)->toBeTrue();
    expect($preference->post_failed)->toBeTrue();
    expect($preference->account_disconnected)->toBeTrue();
    expect($preference->post_note_added)->toBeTrue();
    expect($preference->collaboration)->toBeTrue();
});

test('user can update notification preferences', function () {
    $response = $this->actingAs($this->user)->patchJson(route('app.notifications.preferences.update'), [
        'post_published' => false,
        'post_failed' => true,
        'account_disconnected' => false,
        'post_note_added' => false,
        'collaboration' => false,
    ]);

    $response->assertNoContent();

    $preference = NotificationPreference::where('user_id', $this->user->id)->first();
    expect($preference->post_published)->toBeFalse();
    expect($preference->post_failed)->toBeTrue();
    expect($preference->account_disconnected)->toBeFalse();
    expect($preference->post_note_added)->toBeFalse();
    expect($preference->collaboration)->toBeFalse();
});

test('update validates only the fields it receives', function () {
    $response = $this->actingAs($this->user)->patchJson(route('app.notifications.preferences.update'), [
        'post_published' => 'invalid',
        'post_failed' => true,
    ]);

    $response->assertJsonValidationErrors(['post_published'])
        ->assertJsonMissingValidationErrors(['post_failed', 'post_note_added', 'collaboration']);
});

test('wantsEmailFor respects preferences', function () {
    NotificationPreference::create([
        'user_id' => $this->user->id,
        'post_published' => false,
        'post_failed' => true,
        'account_disconnected' => false,
        'post_note_added' => false,
    ]);

    expect($this->user->wantsEmailFor(Type::PostPublished))->toBeFalse();
    expect($this->user->wantsEmailFor(Type::PostNoteAdded))->toBeFalse();
    expect($this->user->wantsEmailFor(Type::PostFailed))->toBeTrue();
    expect($this->user->wantsEmailFor(Type::AccountDisconnected))->toBeFalse();
});

test('wantsEmailFor defaults to true when no preferences exist', function () {
    expect($this->user->wantsEmailFor(Type::PostPublished))->toBeTrue();
    expect($this->user->wantsEmailFor(Type::PostFailed))->toBeTrue();
    expect($this->user->wantsEmailFor(Type::AccountDisconnected))->toBeTrue();
    expect($this->user->wantsEmailFor(Type::PostNoteAdded))->toBeTrue();
});

test('send notification respects email preferences', function () {
    Mail::fake();

    NotificationPreference::create([
        'user_id' => $this->user->id,
        'post_published' => false,
        'post_failed' => true,
        'account_disconnected' => true,
    ]);

    $post = Post::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->user->id,
    ]);

    (new SendNotification(
        user: $this->user,
        type: Type::PostPublished,
        mailable: new PostPublished($post),
    ))->handle();

    Mail::assertNothingQueued();

    (new SendNotification(
        user: $this->user,
        type: Type::PostFailed,
        mailable: new PostPublishFailed($post),
    ))->handle();

    Mail::assertQueued(PostPublishFailed::class, fn (PostPublishFailed $mail) => $mail->hasTo($this->user->email));
});

test('notification preferences update requires authentication', function () {
    $response = $this->patch(route('app.notifications.preferences.update'), [
        'post_published' => true,
        'post_failed' => true,
        'account_disconnected' => true,
    ]);

    $response->assertRedirect(route('login'));
});

test('wantsEmailFor respects the collaboration preference', function () {
    NotificationPreference::factory()->create(['user_id' => $this->user->id, 'collaboration' => false]);

    expect($this->user->fresh()->wantsEmailFor(Type::Collaboration))->toBeFalse()
        ->and($this->user->fresh()->wantsEmailFor(Type::PostPublished))->toBeTrue();
});

test('patching one preference leaves the others untouched', function () {
    NotificationPreference::factory()->create([
        'user_id' => $this->user->id,
        'post_published' => true,
        'post_failed' => true,
        'account_disconnected' => true,
        'post_note_added' => true,
        'collaboration' => true,
    ]);

    $this->actingAs($this->user)
        ->patchJson(route('app.notifications.preferences.update'), ['post_failed' => false])
        ->assertNoContent();

    $preference = NotificationPreference::query()->where('user_id', $this->user->id)->sole();

    expect($preference->post_failed)->toBeFalse()
        ->and($preference->post_published)->toBeTrue()
        ->and($preference->account_disconnected)->toBeTrue()
        ->and($preference->post_note_added)->toBeTrue()
        ->and($preference->collaboration)->toBeTrue();
});

test('saving a preference shows no success banner', function () {
    $this->actingAs($this->user)
        ->patchJson(route('app.notifications.preferences.update'), ['collaboration' => false])
        ->assertNoContent()
        ->assertSessionMissing('flash.banner');
});
