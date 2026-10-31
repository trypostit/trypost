<?php

declare(strict_types=1);

use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();

    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id, 'account_id' => $this->user->account_id]);
    $this->workspace->members()->attach($this->user->id, membershipPivot('admin'));
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    subscribeAccount($this->user->account);
});

test('a channel avatar is served from the app for the share card', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'avatar_url' => 'social-accounts/avatar.png']);
    Storage::put('social-accounts/avatar.png', file_get_contents(base_path('tests/fixtures/1x1.png')));

    $response = $this->actingAs($this->user)->get(route('app.channels.avatar', $account));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
});

test('the avatar of a channel in another workspace is not found', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => Workspace::factory()->create()->id, 'avatar_url' => 'social-accounts/other.png']);
    Storage::put('social-accounts/other.png', file_get_contents(base_path('tests/fixtures/1x1.png')));

    $this->actingAs($this->user)->get(route('app.channels.avatar', $account))->assertNotFound();
});

test('a guest cannot read a channel avatar', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'avatar_url' => 'social-accounts/avatar.png']);

    $this->get(route('app.channels.avatar', $account))->assertRedirect();
});

test('a channel without an avatar, or whose file is gone, is not found', function (?string $path) {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'avatar_url' => $path]);

    $this->actingAs($this->user)->get(route('app.channels.avatar', $account))->assertNotFound();
})->with([
    'no avatar' => [null],
    'missing file' => ['social-accounts/missing.jpg'],
]);

test('a stored file that is not an image is never served', function () {
    $account = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id, 'avatar_url' => 'social-accounts/page.html']);
    Storage::put('social-accounts/page.html', '<script>alert(1)</script>');

    $this->actingAs($this->user)->get(route('app.channels.avatar', $account))->assertNotFound();
});
