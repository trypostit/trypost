<?php

declare(strict_types=1);

use App\Enums\DataDeletionRequest\Status as DeletionStatus;
use App\Enums\SocialAccount\MetaApp;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Mail\WorkspaceConnectionsDisconnected;
use App\Models\DataDeletionRequest;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config([
        'services.facebook.client_secret' => 'facebook-secret',
        'services.instagram.client_secret' => 'instagram-secret',
        'services.threads.client_secret' => 'threads-secret',
    ]);

    Mail::fake();
});

function metaSignedRequest(string $userId, string $secret, string $algorithm = 'HMAC-SHA256'): string
{
    $encode = fn (string $value): string => rtrim(strtr(base64_encode($value), '+/', '-_'), '=');

    $payload = $encode((string) json_encode([
        'algorithm' => $algorithm,
        'issued_at' => now()->getTimestamp(),
        'user_id' => $userId,
    ]));

    return $encode(hash_hmac('sha256', $payload, $secret, true)).".{$payload}";
}

test('deauthorizing the facebook app flags every channel that user connected through it', function () {
    $workspace = Workspace::factory()->create();
    $page = SocialAccount::factory()->facebook()->create(['workspace_id' => $workspace->id, 'meta' => ['user_id' => 'fb-1']]);
    $instagram = SocialAccount::factory()->state(['platform' => Platform::InstagramFacebook])->create(['workspace_id' => $workspace->id, 'meta' => ['user_id' => 'fb-1']]);
    $otherWorkspacePage = SocialAccount::factory()->facebook()->create(['meta' => ['user_id' => 'fb-1']]);
    $someoneElse = SocialAccount::factory()->facebook()->create(['meta' => ['user_id' => 'fb-2']]);
    $threads = SocialAccount::factory()->threads()->create(['platform_user_id' => 'fb-1']);

    $this->post(route('meta.deauthorize', MetaApp::Facebook), [
        'signed_request' => metaSignedRequest('fb-1', 'facebook-secret'),
    ])->assertNoContent();

    expect($page->fresh()->status)->toBe(Status::TokenExpired)
        ->and($instagram->fresh()->status)->toBe(Status::TokenExpired)
        ->and($otherWorkspacePage->fresh()->status)->toBe(Status::TokenExpired)
        ->and($someoneElse->fresh()->status)->toBe(Status::Connected)
        ->and($threads->fresh()->status)->toBe(Status::Connected);

    Mail::assertQueued(WorkspaceConnectionsDisconnected::class, 2);
    Mail::assertQueued(
        WorkspaceConnectionsDisconnected::class,
        fn (WorkspaceConnectionsDisconnected $mail): bool => $mail->workspace->is($workspace) && $mail->disconnectedAccounts->count() === 2,
    );
});

test('the instagram and threads apps match their own users', function () {
    $byAppScopedId = SocialAccount::factory()->instagram()->create(['platform_user_id' => 'ig-1']);
    $byAccountId = SocialAccount::factory()->instagram()->create(['meta' => ['ig_user_id' => 'ig-1']]);
    $threads = SocialAccount::factory()->threads()->create(['platform_user_id' => 'th-1']);
    $facebook = SocialAccount::factory()->facebook()->create(['meta' => ['user_id' => 'ig-1']]);

    $this->post(route('meta.deauthorize', MetaApp::Instagram), [
        'signed_request' => metaSignedRequest('ig-1', 'instagram-secret'),
    ])->assertNoContent();

    $this->post(route('meta.deauthorize', MetaApp::Threads), [
        'signed_request' => metaSignedRequest('th-1', 'threads-secret'),
    ])->assertNoContent();

    expect($byAppScopedId->fresh()->status)->toBe(Status::TokenExpired)
        ->and($byAccountId->fresh()->status)->toBe(Status::TokenExpired)
        ->and($threads->fresh()->status)->toBe(Status::TokenExpired)
        ->and($facebook->fresh()->status)->toBe(Status::Connected);
});

test('a callback not signed with that app secret is refused', function (string $signedRequest) {
    $page = SocialAccount::factory()->facebook()->create(['meta' => ['user_id' => 'fb-1']]);

    $this->post(route('meta.deauthorize', MetaApp::Facebook), ['signed_request' => $signedRequest])->assertForbidden();
    $this->post(route('meta.data-deletion.store', MetaApp::Facebook), ['signed_request' => $signedRequest])->assertForbidden();

    expect($page->fresh()->status)->toBe(Status::Connected)
        ->and(DataDeletionRequest::count())->toBe(0);
})->with([
    'another app secret' => fn () => metaSignedRequest('fb-1', 'instagram-secret'),
    'another algorithm' => fn () => metaSignedRequest('fb-1', 'facebook-secret', 'HMAC-SHA1'),
    'malformed' => fn () => 'not-a-signed-request',
    'missing' => fn () => '',
]);

test('a callback is refused while the app has no secret', function () {
    config(['services.threads.client_secret' => null]);

    $this->post(route('meta.deauthorize', MetaApp::Threads), [
        'signed_request' => metaSignedRequest('th-1', ''),
    ])->assertForbidden();
});

test('the callbacks start no session', function () {
    $this->post(route('meta.deauthorize', MetaApp::Facebook), [
        'signed_request' => metaSignedRequest('fb-1', 'facebook-secret'),
    ])->assertNoContent()->assertCookieMissing(config('session.cookie'));
});

test('a data deletion request answers with the status page and code', function () {
    $response = $this->post(route('meta.data-deletion.store', MetaApp::Threads), [
        'signed_request' => metaSignedRequest('th-1', 'threads-secret'),
    ])->assertOk();

    $deletionRequest = DataDeletionRequest::sole();

    $response->assertExactJson([
        'url' => route('meta.data-deletion.show', $deletionRequest->confirmation_code),
        'confirmation_code' => $deletionRequest->confirmation_code,
    ]);

    expect($deletionRequest->app)->toBe(MetaApp::Threads)
        ->and($deletionRequest->status)->toBe(DeletionStatus::Completed)
        ->and($deletionRequest->channels_affected)->toBe(0);
});

test('deleting an instagram or threads user removes their own channels with the posts', function (MetaApp $app, string $state) {
    $account = SocialAccount::factory()->{$state}()->create(['platform_user_id' => 'user-1']);
    $post = Post::factory()->forAccount($account)->published()->create(['workspace_id' => $account->workspace_id]);
    $someoneElse = SocialAccount::factory()->{$state}()->create(['platform_user_id' => 'user-2']);

    $this->post(route('meta.data-deletion.store', $app), [
        'signed_request' => metaSignedRequest('user-1', $app->secret()),
    ])->assertOk();

    expect(SocialAccount::find($account->id))->toBeNull()
        ->and(Post::find($post->id))->toBeNull()
        ->and(SocialAccount::find($someoneElse->id))->not->toBeNull()
        ->and(DataDeletionRequest::sole()->channels_affected)->toBe(1);
})->with([
    'instagram' => [MetaApp::Instagram, 'instagram'],
    'threads' => [MetaApp::Threads, 'threads'],
]);

test('deleting a facebook user keeps the business channels and posts but erases their login', function () {
    $page = SocialAccount::factory()->facebook()->create([
        'access_token' => 'page-token',
        'meta' => ['page_id' => 'page-1', 'user_id' => 'fb-1', 'user_token' => 'user-token'],
    ]);
    $post = Post::factory()->forAccount($page)->published()->create(['workspace_id' => $page->workspace_id]);

    $this->post(route('meta.data-deletion.store', MetaApp::Facebook), [
        'signed_request' => metaSignedRequest('fb-1', 'facebook-secret'),
    ])->assertOk();

    $page->refresh();

    expect($page->status)->toBe(Status::TokenExpired)
        ->and($page->access_token)->toBe('')
        ->and($page->meta)->toBe(['page_id' => 'page-1'])
        ->and(Post::find($post->id))->not->toBeNull()
        ->and(DataDeletionRequest::sole()->channels_affected)->toBe(1);

    Mail::assertQueued(WorkspaceConnectionsDisconnected::class, 1);

    $first = DataDeletionRequest::sole();

    $this->post(route('meta.data-deletion.store', MetaApp::Facebook), [
        'signed_request' => metaSignedRequest('fb-1', 'facebook-secret'),
    ])->assertOk();

    expect(DataDeletionRequest::query()->whereKeyNot($first->getKey())->sole()->channels_affected)->toBe(0);
});

test('the status page shows a finished request', function () {
    $deletionRequest = DataDeletionRequest::factory()->completed(2)->create();

    $this->get(route('meta.data-deletion.show', $deletionRequest->confirmation_code))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('meta/DataDeletionStatus')
            ->where('confirmationCode', $deletionRequest->confirmation_code)
            ->where('completed', true));
});

test('the status page shows a request still in progress', function () {
    $deletionRequest = DataDeletionRequest::factory()->create();

    $this->get(route('meta.data-deletion.show', $deletionRequest->confirmation_code))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('completed', false)->where('completedAt', null));
});

test('the status page of an unknown code is not found', function () {
    $this->get(route('meta.data-deletion.show', str_repeat('a', 32)))->assertNotFound();
});
