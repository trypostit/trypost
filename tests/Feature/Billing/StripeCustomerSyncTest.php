<?php

declare(strict_types=1);

use App\Enums\User\ReferralSource;
use App\Models\Account;
use App\Models\User;
use Stripe\ApiRequestor;
use Stripe\HttpClient\ClientInterface;

function fakeStripeCustomerSyncClient(): ClientInterface
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

/**
 * @return array{0: Account, 1: User}
 */
function stripeCustomerOwner(?string $stripeId): array
{
    $account = Account::factory()->create(['stripe_id' => $stripeId]);
    $owner = User::factory()->create([
        'account_id' => $account->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
        'referral_source' => null,
    ]);
    $account->update(['owner_id' => $owner->id]);

    return [$account, $owner->fresh()];
}

beforeEach(function () {
    config(['cashier.secret' => 'sk_test_fake']);
});

afterEach(function () {
    ApiRequestor::setHttpClient(null);
});

test('the owner answering where they found us syncs the stripe customer', function () {
    [, $owner] = stripeCustomerOwner('cus_existing');
    $client = fakeStripeCustomerSyncClient();

    $owner->update(['referral_source' => ReferralSource::ProductHunt]);

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['method'])->toBe('post')
        ->and($client->requests[0]['url'])->toEndWith('/v1/customers/cus_existing')
        ->and($client->requests[0]['params'])->toMatchArray([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'metadata' => ['referral_source' => 'product_hunt'],
        ]);
});

test('the owner renaming themselves syncs the stripe customer name', function () {
    [, $owner] = stripeCustomerOwner('cus_existing');
    $client = fakeStripeCustomerSyncClient();

    $owner->update(['name' => 'Ada King']);

    expect($client->requests)->toHaveCount(1)
        ->and($client->requests[0]['params'])->toMatchArray(['name' => 'Ada King']);
});

test('the stripe customer is not touched', function (Closure $update) {
    $client = fakeStripeCustomerSyncClient();

    $update();

    expect($client->requests)->toBe([]);
})->with([
    'an attribute stripe does not hold changes' => [function () {
        [, $owner] = stripeCustomerOwner('cus_existing');
        $owner->update(['timezone' => 'America/Sao_Paulo']);
    }],
    'the account has no stripe customer yet' => [function () {
        [, $owner] = stripeCustomerOwner(null);
        $owner->update(['referral_source' => ReferralSource::Google]);
    }],
    'a member who is not the owner changes' => [function () {
        [$account] = stripeCustomerOwner('cus_existing');
        $member = User::factory()->create(['account_id' => $account->id, 'referral_source' => null]);
        $member->update(['referral_source' => ReferralSource::Google]);
    }],
]);
