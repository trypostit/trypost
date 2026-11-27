<?php

declare(strict_types=1);

use App\Console\Commands\Admin\SyncPlatformQuotas;
use App\Enums\Admin\QuotaSource;
use App\Services\Admin\Quotas\YouTubeQuotaReader;
use App\Support\Admin\QuotaSnapshots;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('trypost.admin.quotas.youtube', [
        'enabled' => true,
        'project_id' => 'trypost-test',
        'credentials' => base64_encode('{"type":"service_account"}'),
        'timezone' => 'America/Los_Angeles',
    ]);
    config()->set('trypost.admin.quotas.x', ['enabled' => true, 'bearer_token' => 'x-bearer']);
});

function adminXUsageDay(): string
{
    return now('UTC')->subDay()->toDateString();
}

function adminXGrantExpiry(): string
{
    return now('UTC')->addMonthNoOverflow()->startOfDay()->format('Y-m-d\\TH:i:s\\Z');
}

function adminFakeXUsage(): void
{
    $usageDay = adminXUsageDay();
    $day = "{$usageDay}T00:00:00.000Z";
    $expiry = adminXGrantExpiry();
    $api = config('trypost.platforms.x.api');

    Http::fake([
        "{$api}/usage/credits*" => Http::response(['data' => [
            'total_balance' => 42.5,
            'prepaid_balance' => 40.0,
            'free_balance' => 2.5,
            'free_grants' => [['amount' => 2.5, 'expires_at' => $expiry]],
        ]]),
        "{$api}/usage/tweets?days=90&usage.fields=daily_client_app_usage" => Http::response(['data' => [
            'daily_client_app_usage' => [
                ['client_app_id' => '30232015', 'usage_result_count' => 1, 'usage' => [
                    ['date' => $day, 'usage' => '700'],
                ]],
            ],
        ]]),
        "{$api}/usage/tweets*" => Http::response(['data' => [
            'project_usage' => '1200',
            'project_cap' => '3000000',
            'cap_reset_day' => 12,
            'project_id' => '1234567890',
            'daily_project_usage' => ['project_id' => '1234567890', 'usage' => [
                ['date' => $day, 'usage' => '700'],
            ]],
        ]]),
    ]);
}

test('writes a youtube snapshot summed per quota metric since midnight Pacific', function () {
    adminFakeXUsage();
    $this->travelTo(CarbonImmutable::now('UTC')->startOfDay()->addHours(15));
    $requests = [];

    $this->partialMock(YouTubeQuotaReader::class, function ($mock) use (&$requests) {
        $mock->shouldReceive('fetchTimeSeries')->andReturnUsing(function (array $query) use (&$requests) {
            $requests[] = $query;

            if (str_contains($query['filter'], 'quota/limit')) {
                return [['labels' => ['quota_metric' => 'youtube.googleapis.com/default', 'limit_name' => 'defaultPerDayPerProject'], 'value' => 10000]];
            }

            return [['labels' => ['quota_metric' => 'youtube.googleapis.com/default'], 'value' => 3200]];
        });
    });

    $this->artisan(SyncPlatformQuotas::class)->assertSuccessful();

    $snapshot = QuotaSnapshots::latest(QuotaSource::YouTube);
    $midnightPacific = CarbonImmutable::now('America/Los_Angeles')->startOfDay()->utc();

    expect(data_get($snapshot, 'data.quotas.0'))->toBe([
        'metric' => 'youtube.googleapis.com/default',
        'usage' => 3200,
        'limits' => [['name' => 'defaultPerDayPerProject', 'value' => 10000]],
    ])
        ->and(data_get($snapshot, 'data.since'))->toBe($midnightPacific->toIso8601String())
        ->and($requests[0]['interval.startTime'])->toBe($midnightPacific->format('Y-m-d\TH:i:s\Z'));
});

test('writes an x snapshot with every field of the credits and usage endpoints', function () {
    config()->set('trypost.admin.quotas.youtube.enabled', false);
    adminFakeXUsage();

    $this->artisan(SyncPlatformQuotas::class)->assertSuccessful();

    $snapshot = QuotaSnapshots::latest(QuotaSource::X);

    expect(data_get($snapshot, 'data.credits'))->toBe([
        'total_balance' => 42.5,
        'prepaid_balance' => 40.0,
        'free_balance' => 2.5,
        'free_grants' => [['amount' => 2.5, 'expires_at' => adminXGrantExpiry()]],
    ])
        ->and(data_get($snapshot, 'data.usage'))->toBe([
            'project_id' => '1234567890',
            'project_usage' => 1200,
            'project_cap' => 3000000,
            'cap_reset_day' => 12,
            'daily_project_usage' => [['date' => adminXUsageDay(), 'usage' => 700]],
            'daily_client_app_usage' => [['client_app_id' => '30232015', 'usage' => [['date' => adminXUsageDay(), 'usage' => 700]]]],
        ]);

    Http::assertSent(fn ($request): bool => str_contains($request->url(), 'usage/tweets')
        && $request['usage.fields'] === 'cap_reset_day,daily_project_usage,project_cap,project_id,project_usage');

    Http::assertSent(fn ($request): bool => $request->hasHeader('Authorization', 'Bearer x-bearer'));
});

test('keeps the x reading when x fails the per-app usage', function () {
    config()->set('trypost.admin.quotas.youtube.enabled', false);
    $api = config('trypost.platforms.x.api');

    Http::fake([
        "{$api}/usage/credits*" => Http::response(['data' => ['total_balance' => 10.0, 'prepaid_balance' => 10.0, 'free_balance' => 0.0, 'free_grants' => []]]),
        "{$api}/usage/tweets?days=90&usage.fields=daily_client_app_usage" => Http::response(['status' => 503], 503),
        "{$api}/usage/tweets*" => Http::response(['data' => ['project_usage' => '5', 'project_cap' => '3000000', 'cap_reset_day' => 14]]),
    ]);

    $this->artisan(SyncPlatformQuotas::class)->assertSuccessful();

    $snapshot = QuotaSnapshots::latest(QuotaSource::X);

    expect(data_get($snapshot, 'data.usage.project_usage'))->toBe(5)
        ->and(data_get($snapshot, 'data.usage.daily_client_app_usage'))->toBeNull();
});

test('skips a source that is off or has no credentials', function () {
    Http::fake();
    config()->set('trypost.admin.quotas.youtube.enabled', false);
    config()->set('trypost.admin.quotas.x.bearer_token', null);

    $this->artisan(SyncPlatformQuotas::class)->assertSuccessful();

    Http::assertNothingSent();
    expect(QuotaSnapshots::latest(QuotaSource::YouTube))->toBeNull()
        ->and(QuotaSnapshots::latest(QuotaSource::X))->toBeNull();
});

test('keeps going when one source fails and keeps the last good snapshot', function () {
    adminFakeXUsage();
    QuotaSnapshots::put(QuotaSource::YouTube, ['quotas' => [], 'since' => 'before']);

    $this->partialMock(YouTubeQuotaReader::class, fn ($mock) => $mock
        ->shouldReceive('fetchTimeSeries')->andThrow(new RuntimeException('Google is down')));

    $this->artisan(SyncPlatformQuotas::class)->assertSuccessful();

    expect(data_get(QuotaSnapshots::latest(QuotaSource::YouTube), 'data.since'))->toBe('before')
        ->and(QuotaSnapshots::latest(QuotaSource::X))->not->toBeNull();
});

test('keeps only the latest reading, no history', function () {
    QuotaSnapshots::put(QuotaSource::X, ['index' => 1]);
    QuotaSnapshots::put(QuotaSource::X, ['index' => 2]);

    expect(data_get(QuotaSnapshots::latest(QuotaSource::X), 'data'))->toBe(['index' => 2]);
});

test('runs four times a day', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event): bool => str_contains((string) $event->command, 'admin:sync-platform-quotas'));

    expect($event?->expression)->toBe('0 */6 * * *');
});
