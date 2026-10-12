<?php

declare(strict_types=1);

use App\Enums\User\Persona;
use App\Enums\User\ReferralSource;
use App\Jobs\Billing\SyncStripeCustomerMetadata;
use App\Models\Account;
use App\Models\User;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

function fakeStripeCustomerMetadataClient(): ClientInterface
{
    $client = new class implements ClientInterface
    {
        /** @var array<int, array{method: string, url: string, params: array<string, mixed>}> */
        public array $requests = [];

        public function request($method, $absUrl, $headers, $params, $hasFile, $apiMode = 'v1', $maxNetworkRetries = null): array
        {
            $this->requests[] = ['method' => $method, 'url' => $absUrl, 'params' => $params];

            return [json_encode(['id' => 'cus_existing', 'object' => 'customer']), 200, []];
        }
    };

    ApiRequestor::setHttpClient($client);

    return $client;
}

beforeEach(function () {
    config(['cashier.secret' => 'sk_test_fake']);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('it writes the owner metadata to the existing stripe customer', function () {
    $client = fakeStripeCustomerMetadataClient();

    $account = Account::factory()->create(['stripe_id' => 'cus_existing']);
    $owner = User::factory()->create([
        'account_id' => $account->id,
        'utm_source' => 'google',
        'persona' => Persona::Agency,
        'referral_source' => ReferralSource::ProductHunt,
    ]);
    $account->update(['owner_id' => $owner->id]);

    SyncStripeCustomerMetadata::dispatchSync($account->id);

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['method'])->toBe('post')
        ->and($client->requests[0]['url'])->toEndWith('/v1/customers/cus_existing')
        ->and($client->requests[0]['params'])->toBe([
            'metadata' => [
                'utm_source' => 'google',
                'persona' => 'agency',
                'referral_source' => 'product_hunt',
            ],
        ]);
});

test('it does not create a stripe customer for an account without one', function () {
    $client = fakeStripeCustomerMetadataClient();

    $account = Account::factory()->create(['stripe_id' => null]);

    SyncStripeCustomerMetadata::dispatchSync($account->id);

    expect($client->requests)->toBe([])
        ->and($account->fresh()->stripe_id)->toBeNull();
});
