<?php

declare(strict_types=1);

use App\Actions\Admin\UserProfile\BuildUserHealth;
use App\Actions\Admin\Users\BuildChurnRisk;
use App\Actions\Admin\Users\BuildUsageInsights;
use App\Dto\Analytics\DateRange;
use App\Enums\Account\LifecycleStage;
use App\Enums\Post\PublishStatus;
use App\Enums\SocialAccount\Platform;
use App\Enums\SocialAccount\Status;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Admin\AccountActivity;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Stripe\ApiRequestor;

beforeEach(function () {
    $this->now = CarbonImmutable::now('UTC')->startOfDay()->addHours(12);
    $this->travelTo($this->now);
    $this->admin = backofficeAdmin();
});

afterEach(fn () => ApiRequestor::setHttpClient(null));

/**
 * An owner with a workspace and an X channel of the given status.
 *
 * @return array{0: User, 1: SocialAccount}
 */
function adminOwnerWithChannel(string $name, Status $status = Status::Connected): array
{
    $owner = workspaceOwner(['name' => $name]);
    $channel = SocialAccount::factory()->create(['workspace_id' => $owner->current_workspace_id, 'platform' => Platform::X, 'status' => $status]);

    return [$owner, $channel];
}

test('usage ranks the accounts by posts, channels, automation and failures, against the period before', function () {
    [$busy, $busyChannel] = adminOwnerWithChannel('Busy');
    [$quiet, $quietChannel] = adminOwnerWithChannel('Quiet');

    foreach (range(1, 3) as $index) {
        adminPostSentAt($busyChannel, $this->now->subHours($index), 'published', ['created_via' => 'api']);
    }

    adminPostSentAt($quietChannel, $this->now->subHour());
    adminPostSentAt($quietChannel, $this->now->subHour(), 'failed');
    adminPostSentAt($quietChannel, $this->now->subDays(10));

    $usage = BuildUsageInsights::execute(AccountActivity::during(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')));

    expect($usage['totals'])->toMatchArray(['active_accounts' => 2, 'posts' => 4, 'posts_per_active' => 2.0, 'channels_per_account' => 1.0])
        ->and($usage['previous'])->toMatchArray(['active_accounts' => 1, 'posts' => 1])
        ->and($usage['top_accounts'][0])->toMatchArray(['name' => 'Busy', 'posts' => 3, 'automated' => 3, 'channels' => 1, 'failed' => 0])
        ->and(collect($usage['top_accounts'])->firstWhere('name', 'Quiet'))->toMatchArray(['posts' => 1, 'failed' => 1])
        ->and(collect($usage['lifecycle']['stages'])->pluck('key')->all())
        ->toBe(collect(BuildUsageInsights::CUSTOMER_STAGES)->map->value->all());
});

test('churn risk flags the paying accounts that stopped, cancel, owe or lost their channels', function () {
    fakeStripeReads(['price_123' => [1900, 'month']]);

    [$fine, $fineChannel] = adminOwnerWithChannel('Fine');
    [$leaving, $leavingChannel] = adminOwnerWithChannel('Leaving', Status::TokenExpired);
    adminPostSentAt($leavingChannel, $this->now->subDays(30));
    foreach ([$fine, $leaving] as $owner) {
        adminBackdateSubscription(subscribeAccount($owner->account), $this->now->subDays(30));
    }
    adminPostSentAt($fineChannel, $this->now->subHour());
    $leaving->account->subscription()->update(['ends_at' => $this->now->addDays(5)]);

    $churn = BuildChurnRisk::execute(AccountActivity::during(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')));

    expect($churn)->toMatchArray(['base' => 2, 'active' => 1, 'at_risk' => 1, 'mrr_at_risk_cents' => 1900])
        ->and($churn['accounts'][0]['name'])->toBe('Leaving')
        ->and($churn['accounts'][0]['signals'])->toEqualCanonicalizing(['dormant', 'cancelling', 'channels_broken'])
        ->and($churn['accounts'][0]['stage'])->toBe(LifecycleStage::Dormant->value);
});

test('the people insights page shows usage and churn risk', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.users.insights.index'))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->component('admin/users/Insights')
            ->has('usage.top_accounts')
            ->has('churn.accounts'),
        );
});

test('a user profile reads the account health and churn risk the way the insights do', function () {
    fakeStripeReads(['price_123' => [1900, 'month']]);

    [$owner, $channel] = adminOwnerWithChannel('Leaving', Status::TokenExpired);
    adminBackdateSubscription(subscribeAccount($owner->account, ['ends_at' => $this->now->addDays(5)]), $this->now->subDays(60));
    adminPostSentAt($channel, $this->now->subDays(40));
    adminPostSentAt($channel, $this->now->subDays(35), 'published', ['created_via' => 'api']);
    adminPostSentAt($channel, $this->now->subDays(25));
    adminPostSentAt($channel, $this->now->subDays(24), 'failed');

    $health = BuildUserHealth::execute($owner);

    expect($health['stage'])->toBe(LifecycleStage::Dormant->value)
        ->and($health['posts'])->toMatchArray(['sent' => 1, 'previous' => 2, 'automated' => 0, 'failed' => 1, 'failure_rate' => 50.0])
        ->and($health['channels'])->toBe(['total' => 1, 'broken' => 1])
        ->and($health['risk'])->toMatchArray(['level' => 'high', 'mrr_cents' => 1900])
        ->and($health['risk']['signals'])->toEqualCanonicalizing(['dormant', 'cancelling', 'channels_broken']);
});

test('a user profile shows no churn risk for an account that pays nothing', function () {
    [$owner] = adminOwnerWithChannel('Someone');

    expect(BuildUserHealth::execute($owner)['risk'])->toBeNull();
});

test('a post the network rejected in review counts as failed, as everywhere in the backoffice', function () {
    fakeStripeReads(['price_123' => [1900, 'month']]);
    [$owner, $channel] = adminOwnerWithChannel('Rejected');
    adminBackdateSubscription(subscribeAccount($owner->account), $this->now->subDays(30));
    adminPostSentAt($channel, $this->now->subHour(), 'failed');
    Post::query()->where('social_account_id', $channel->id)->toBase()->update(['publish_status' => PublishStatus::Rejected->value]);

    $range = DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC');

    expect(collect(BuildUsageInsights::execute(AccountActivity::during($range))['top_accounts'])->firstWhere('name', 'Rejected')['failed'])->toBe(1)
        ->and(BuildUserHealth::execute($owner)['posts']['failed'])->toBe(1);
});

test('usage and churn count a post on the day it went out in the viewer\'s zone', function () {
    fakeStripeReads(['price_123' => [1900, 'month']]);
    [$owner, $channel] = adminOwnerWithChannel('Night owl');
    adminBackdateSubscription(subscribeAccount($owner->account), $this->now->subDays(30));
    $today = $this->now->startOfDay();
    adminPostSentAt($channel, $today->addHour());

    $yesterday = $today->subDay()->toDateString();
    $saoPaulo = DateRange::fromDates($yesterday, $yesterday, 'America/Sao_Paulo');
    $utc = DateRange::fromDates($yesterday, $yesterday, 'UTC');

    expect(BuildUsageInsights::execute(AccountActivity::during($saoPaulo))['totals']['posts'])->toBe(1)
        ->and(BuildUsageInsights::execute(AccountActivity::during($utc))['totals']['posts'])->toBe(0)
        ->and(BuildChurnRisk::execute(AccountActivity::during($saoPaulo))['active'])->toBe(1)
        ->and(BuildChurnRisk::execute(AccountActivity::during($utc))['active'])->toBe(0);
});

test('each churn signal weighs into the risk level', function (array $subscription, LifecycleStage $stage, ?array $channels, array $posts, array $signals, ?string $level) {
    fakeStripeReads(['price_123' => [1900, 'month']]);
    [$owner] = adminOwnerWithChannel('Someone');
    adminBackdateSubscription(subscribeAccount($owner->account, $subscription), $this->now->subDays(30));
    $risk = BuildChurnRisk::assess($owner->account->fresh(), $stage, $channels, ...$posts);

    expect($risk['signals'])->toEqualCanonicalizing($signals)
        ->and($risk['level'])->toBe($level)
        ->and($risk['mrr_cents'])->toBe(1900);
})->with([
    'past due' => [['stripe_status' => 'past_due'], LifecycleStage::Active, ['total' => 1, 'broken' => 0], [5, 5, 0], ['past_due'], 'medium'],
    'cancelling' => [['ends_at' => now()->addDays(5)], LifecycleStage::Active, ['total' => 1, 'broken' => 0], [5, 5, 0], ['cancelling'], 'medium'],
    'dormant' => [[], LifecycleStage::Dormant, ['total' => 1, 'broken' => 0], [0, 0, 0], ['dormant'], 'medium'],
    'never posted' => [[], LifecycleStage::Connected, ['total' => 1, 'broken' => 0], [0, 0, 0], ['never_posted'], 'medium'],
    'no channels' => [[], LifecycleStage::Onboarding, null, [0, 0, 0], ['no_channels'], 'medium'],
    'channels broken' => [[], LifecycleStage::Active, ['total' => 2, 'broken' => 1], [5, 5, 0], ['channels_broken'], 'medium'],
    'at risk' => [[], LifecycleStage::AtRisk, ['total' => 1, 'broken' => 0], [0, 0, 0], ['at_risk'], 'low'],
    'posting dropped' => [[], LifecycleStage::Active, ['total' => 1, 'broken' => 0], [5, 10, 0], ['posting_dropped'], 'low'],
    'failing' => [[], LifecycleStage::Active, ['total' => 1, 'broken' => 0], [5, 5, 5], ['failing'], 'low'],
    'several at once' => [['stripe_status' => 'past_due'], LifecycleStage::Dormant, ['total' => 1, 'broken' => 0], [0, 0, 0], ['past_due', 'dormant'], 'high'],
    'none' => [[], LifecycleStage::Active, ['total' => 1, 'broken' => 0], [5, 5, 0], [], null],
]);

test('an account that renewed after cancelling is read through the subscription it pays now', function () {
    fakeStripeReads(['price_123' => [1900, 'month'], 'price_old' => [1200, 'month']]);
    [$owner, $channel] = adminOwnerWithChannel('Renewed');
    adminPostSentAt($channel, $this->now->subHour());
    $renewed = subscribeAccount($owner->account);
    $cancelling = subscribeAccount($owner->account, ['stripe_price' => 'price_old', 'ends_at' => $this->now->addDays(5)]);
    adminBackdateSubscription($cancelling, $this->now->subDays(60));
    adminBackdateSubscription($renewed, $this->now->subDays(30));

    $churn = BuildChurnRisk::execute(AccountActivity::during(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')));

    expect($churn)->toMatchArray(['base' => 1, 'at_risk' => 0, 'mrr_at_risk_cents' => 0])
        ->and(BuildUserHealth::execute($owner)['risk'])->toBe(['signals' => [], 'score' => 0, 'level' => null, 'mrr_cents' => 1900]);
});

test('a customer who subscribed less than the settling days ago is not flagged for having no channel or post yet', function () {
    fakeStripeReads(['price_123' => [1900, 'month']]);
    $owner = workspaceOwner(['name' => 'Newcomer']);
    adminBackdateSubscription(subscribeAccount($owner->account), $this->now->subDays(BuildChurnRisk::SETTLING_DAYS - 4));

    $churn = BuildChurnRisk::execute(AccountActivity::during(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')));
    $risk = BuildUserHealth::execute($owner)['risk'];

    expect($churn)->toMatchArray(['base' => 1, 'at_risk' => 0])
        ->and($risk['signals'])->toBe([])
        ->and($risk['level'])->toBeNull();
});

test('the lifecycle counts only the accounts with access, by stage', function () {
    [$customer, $channel] = adminOwnerWithChannel('Customer');
    subscribeAccount($customer->account);
    adminPostSentAt($channel, $this->now->subDay());
    [$former] = adminOwnerWithChannel('Former');
    subscribeAccount($former->account, ['stripe_status' => 'canceled', 'ends_at' => $this->now->subDay()]);
    [$expired] = adminOwnerWithChannel('Expired trial');
    $expired->account->forceFill(['trial_ends_at' => $this->now->subDay()])->save();

    $lifecycle = BuildUsageInsights::execute(AccountActivity::during(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')))['lifecycle'];

    expect($lifecycle['customers'])->toBe(1)
        ->and(collect($lifecycle['stages'])->pluck('count', 'key')->all())->toBe([
            LifecycleStage::Onboarding->value => 0,
            LifecycleStage::Connected->value => 0,
            LifecycleStage::Active->value => 1,
            LifecycleStage::AtRisk->value => 0,
            LifecycleStage::Dormant->value => 0,
        ]);
});

test('an owner who signed up without a readable name is named by their email', function () {
    [$owner, $channel] = adminOwnerWithChannel('_');
    adminPostSentAt($channel, $this->now->subHour());

    $top = BuildUsageInsights::execute(AccountActivity::during(DateRange::fromDates($this->now->subDays(6)->toDateString(), $this->now->toDateString(), 'UTC')))['top_accounts'];

    expect(collect($top)->firstWhere('user_id', $owner->id)['name'])->toBe($owner->email);
});
