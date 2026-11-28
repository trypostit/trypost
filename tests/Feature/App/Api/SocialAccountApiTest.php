<?php

declare(strict_types=1);

use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;

beforeEach(function () {
    $result = createApiTestToken();
    $this->user = $result['user'];
    $this->workspace = $result['workspace'];
    $this->plainToken = $result['plain_token'];
});

it('lists social accounts', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::X,
    ]);

    $response = $this->getJson(route('api.social-accounts.index'), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertJsonCount(2, 'data');
    $response->assertJsonStructure([
        'data' => ['*' => ['id', 'platform', 'display_name', 'username', 'status']],
    ]);
});

it('does not expose tokens in social accounts list', function () {
    SocialAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'platform' => Platform::LinkedIn,
    ]);

    $response = $this->getJson(route('api.social-accounts.index'), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertJsonMissingPath('data.0.is_active');
    $response->assertJsonMissing(['access_token']);
    $response->assertJsonMissing(['refresh_token']);
});

it('requires authentication to list social accounts', function () {
    $response = $this->getJson(route('api.social-accounts.index'));

    $response->assertUnauthorized();
});

it('returns empty list when no social accounts', function () {
    $response = $this->getJson(route('api.social-accounts.index'), [
        'Authorization' => "Bearer {$this->plainToken}",
    ]);

    $response->assertOk();
    $response->assertJsonCount(0, 'data');
});
