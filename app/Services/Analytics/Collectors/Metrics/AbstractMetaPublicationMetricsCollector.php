<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\MetaAnalyticsResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

abstract class AbstractMetaPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    /** @var list<string> */
    protected array $refusals = [];

    /**
     * Graph answers code 100, subcode 33 ("Object with ID ... does not exist, cannot be loaded
     * due to missing permissions, or does not support this operation") both for a post deleted
     * on the network and for a token that can no longer read it, and the per-metric reads above
     * treat that answer as one refused metric. So when a read fails as malformed, the post itself
     * is read: if Graph says it does not exist while the account's own node (the Instagram user,
     * the Facebook Page) still reads with the same token, the post is gone.
     *
     * @param  callable(): PublicationMetricObservation  $collect
     */
    protected function detectingDeletion(AnalyticsPublication $publication, string $graphBase, callable $collect): PublicationMetricObservation
    {
        try {
            return $collect();
        } catch (AnalyticsCollectionException $exception) {
            if ($exception->category === 'malformed' && $this->deletedOnNetwork($publication, $graphBase)) {
                throw AnalyticsCollectionException::gone("{$publication->platform->value} no longer returns this post.");
            }

            throw $exception;
        }
    }

    private function deletedOnNetwork(AnalyticsPublication $publication, string $graphBase): bool
    {
        $account = $this->account($publication);

        try {
            $this->get($account, "{$graphBase}/{$publication->remote_id}", ['fields' => 'id']);

            return false;
        } catch (AnalyticsCollectionException $exception) {
            if (! $exception->missing) {
                return false;
            }
        }

        try {
            $this->get($account, "{$graphBase}/{$account->platform_user_id}", ['fields' => 'id']);

            return true;
        } catch (AnalyticsCollectionException) {
            return false;
        }
    }

    protected function successfulResponse(Response $response): Response
    {
        return MetaAnalyticsResponse::successful($response, 'publication metrics collection');
    }

    /**
     * @param  list<string>  $metrics
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    protected function optionalInsightItems(SocialAccount $account, string $url, array $metrics, array $query = []): array
    {
        try {
            $response = $this->get($account, $url, ['metric' => implode(',', $metrics), ...$query]);
        } catch (AnalyticsCollectionException $exception) {
            if (! in_array($exception->category, ['permission', 'malformed'], true)) {
                throw $exception;
            }

            if (count($metrics) > 1) {
                return array_merge(...array_map(
                    fn (string $metric): array => $this->optionalInsightItems($account, $url, [$metric], $query),
                    $metrics,
                ));
            }

            $this->refusals[] = $exception->category;
            Log::info('analytics.meta_metric_rejected', [
                'platform' => $account->platform->value,
                'path' => parse_url($url, PHP_URL_PATH),
                'metric' => data_get($metrics, 0),
                'category' => $exception->category,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }

        $data = $response->json('data');

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }
}
