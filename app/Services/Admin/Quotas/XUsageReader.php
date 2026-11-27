<?php

declare(strict_types=1);

namespace App\Services\Admin\Quotas;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Everything X's usage API reports for the app's project: the credit balance
 * (`/2/usage/credits`) and the Post usage (`/2/usage/tweets`), read with the
 * app's bearer token. Nothing is derived here: the card shows these fields.
 *
 * @see https://docs.x.com/x-api/usage/get-usage-credits
 * @see https://docs.x.com/x-api/usage/get-usage
 */
class XUsageReader
{
    /** Days of daily usage asked for (X allows 1 to 90). */
    public const int USAGE_DAYS = 90;

    public function isConfigured(): bool
    {
        if (! config('trypost.admin.quotas.x.enabled')) {
            return false;
        }

        return filled(config('trypost.admin.quotas.x.bearer_token'));
    }

    /**
     * @return array{
     *     credits: array{total_balance: float, prepaid_balance: float, free_balance: float, free_grants: list<array{amount: float, expires_at: string|null}>},
     *     usage: array{
     *         project_id: string|null,
     *         project_usage: int,
     *         project_cap: int|null,
     *         cap_reset_day: int|null,
     *         daily_project_usage: list<array{date: string, usage: int}>,
     *         daily_client_app_usage: list<array{client_app_id: string|null, usage: list<array{date: string, usage: int}>}>
     *     }
     * }
     */
    public function read(): array
    {
        $api = (string) config('trypost.platforms.x.api');
        $client = Http::withToken((string) config('trypost.admin.quotas.x.bearer_token'))->acceptJson()->timeout(20);

        $credits = $client->get("{$api}/usage/credits")->throw()->json('data', []);
        $usage = $client->get("{$api}/usage/tweets", [
            'days' => self::USAGE_DAYS,
            'usage.fields' => 'cap_reset_day,daily_project_usage,project_cap,project_id,project_usage',
        ])->throw()->json('data', []);

        $clientApps = $this->clientAppUsage($client, $api);

        return [
            'credits' => [
                'total_balance' => (float) data_get($credits, 'total_balance', 0),
                'prepaid_balance' => (float) data_get($credits, 'prepaid_balance', 0),
                'free_balance' => (float) data_get($credits, 'free_balance', 0),
                'free_grants' => collect(data_get($credits, 'free_grants', []))
                    ->map(fn (array $grant): array => [
                        'amount' => (float) data_get($grant, 'amount', 0),
                        'expires_at' => data_get($grant, 'expires_at'),
                    ])
                    ->values()
                    ->all(),
            ],
            'usage' => [
                'project_id' => data_get($usage, 'project_id'),
                'project_usage' => (int) data_get($usage, 'project_usage', 0),
                'project_cap' => $this->integerOrNull(data_get($usage, 'project_cap')),
                'cap_reset_day' => $this->integerOrNull(data_get($usage, 'cap_reset_day')),
                'daily_project_usage' => $this->days(data_get($usage, 'daily_project_usage.usage', [])),
                'daily_client_app_usage' => $clientApps,
            ],
        ];
    }

    /**
     * The per-app daily usage, asked on its own: X answers 503 whenever
     * `daily_client_app_usage` is among the fields (seen November 2026, for
     * any `days`), so it must never take the rest of the reading down. Null
     * when X does not return it; the card then shows the project's total.
     *
     * @return list<array{client_app_id: string|null, usage: list<array{date: string, usage: int}>}>|null
     */
    private function clientAppUsage(PendingRequest $client, string $api): ?array
    {
        $response = $client->get("{$api}/usage/tweets", [
            'days' => self::USAGE_DAYS,
            'usage.fields' => 'daily_client_app_usage',
        ]);

        if ($response->failed()) {
            return null;
        }

        return collect($response->json('data.daily_client_app_usage', []))
            ->map(fn (array $app): array => [
                'client_app_id' => data_get($app, 'client_app_id'),
                'usage' => $this->days(data_get($app, 'usage', [])),
            ])
            ->values()
            ->all();
    }

    /**
     * X's daily entries, its ISO 8601 date-time cut to the UTC day.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return list<array{date: string, usage: int}>
     */
    private function days(array $entries): array
    {
        return collect($entries)
            ->map(fn (array $day): array => [
                'date' => substr((string) data_get($day, 'date'), 0, 10),
                'usage' => (int) data_get($day, 'usage', 0),
            ])
            ->sortBy('date')
            ->values()
            ->all();
    }

    /**
     * X sends these counts as strings, numbers or nothing at all: the value
     * comes straight from the decoded JSON, which is why it is `mixed`.
     */
    private function integerOrNull(mixed $value): ?int
    {
        if (blank($value)) {
            return null;
        }

        return (int) $value;
    }
}
