<?php

declare(strict_types=1);

use App\Console\Commands\RefreshLinkedInPageProfiles;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
});

function fakeLinkedInOrganizations(array $organizations): void
{
    Http::fake([
        config('trypost.platforms.linkedin.api').'/v2/organizationAcls*' => Http::response([
            'elements' => array_map(fn (array $organization): array => ['organization~' => $organization], $organizations),
        ]),
        'https://media.licdn.com/*' => Http::response('logo-bytes', 200, ['Content-Type' => 'image/png']),
    ]);
}

test('a linkedin page due for a refresh is read again from linkedin', function () {
    $page = SocialAccount::factory()->linkedinPage()->create([
        'platform_user_id' => '1001',
        'display_name' => 'Old Name',
        'profile_refreshed_at' => now()->subWeeks(7)->subDay(),
    ]);

    fakeLinkedInOrganizations([[
        'id' => 1001,
        'localizedName' => 'New Name',
        'vanityName' => 'new-name',
        'logoV2' => ['original~' => ['elements' => [['identifiers' => [['identifier' => 'https://media.licdn.com/logo.png']]]]]],
    ]]);

    $this->artisan(RefreshLinkedInPageProfiles::class)->assertSuccessful();

    $page->refresh();

    expect($page->display_name)->toBe('New Name')
        ->and($page->username)->toBe('new-name')
        ->and($page->getRawOriginal('avatar_url'))->not->toBeNull()
        ->and($page->profile_refreshed_at->isToday())->toBeTrue();
});

test('a page refreshed recently and member channels are not read again', function () {
    SocialAccount::factory()->linkedinPage()->create(['profile_refreshed_at' => now()->subWeeks(6)]);
    SocialAccount::factory()->linkedin()->create(['profile_refreshed_at' => now()->subYear()]);
    Http::fake();

    $this->artisan(RefreshLinkedInPageProfiles::class)->assertSuccessful();

    Http::assertNothingSent();
});

test('a page that can no longer be read keeps its profile until it is eight weeks old', function () {
    $recent = SocialAccount::factory()->linkedinPage()->tokenExpired()->create([
        'display_name' => 'Recent',
        'profile_refreshed_at' => now()->subWeeks(7)->subDay(),
    ]);
    $stale = SocialAccount::factory()->linkedinPage()->tokenExpired()->create([
        'display_name' => 'Stale',
        'username' => 'stale',
        'avatar_url' => 'social-accounts/stale.png',
        'profile_refreshed_at' => now()->subWeeks(8)->subDay(),
    ]);
    Http::fake();

    $this->artisan(RefreshLinkedInPageProfiles::class)->assertSuccessful();

    expect($recent->fresh()->display_name)->toBe('Recent')
        ->and($stale->fresh()->only(['display_name', 'username']))->toBe(['display_name' => null, 'username' => null])
        ->and($stale->fresh()->getRawOriginal('avatar_url'))->toBeNull();

    Http::assertNothingSent();
});

test('a connected page the member no longer administers is cleared once stale', function () {
    $page = SocialAccount::factory()->linkedinPage()->create([
        'platform_user_id' => '1001',
        'display_name' => 'Gone',
        'profile_refreshed_at' => now()->subWeeks(9),
    ]);

    fakeLinkedInOrganizations([]);

    $this->artisan(RefreshLinkedInPageProfiles::class)->assertSuccessful();

    expect($page->fresh()->display_name)->toBeNull();
});

test('connecting a channel records when its profile was read', function () {
    $account = SocialAccount::connectIdentity(
        Workspace::factory()->create(),
        Platform::LinkedInPage,
        '2002',
        ['display_name' => 'Fresh', 'access_token' => 'token', 'status' => Status::Connected],
    );

    expect($account->profile_refreshed_at?->isToday())->toBeTrue();
});
