<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\PublicationContentType;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Support\Analytics\MetaAnalyticsResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

abstract class AbstractMetaPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    /** @var list<string> */
    protected array $refusals = [];

    abstract protected function read(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation;

    abstract protected function graphBaseUrl(SocialAccount $account): string;

    /**
     * The node the account's token reads as itself, asked to tell a deleted post
     * from a token that lost access.
     */
    protected function accountNode(SocialAccount $account): string
    {
        return 'me';
    }

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        try {
            return $this->read($publication, $date);
        } catch (AnalyticsCollectionException $exception) {
            if ($exception->category === 'malformed' && $this->deletedOnNetwork($publication)) {
                throw AnalyticsCollectionException::gone("{$publication->platform->value} no longer returns this post.");
            }

            throw $exception;
        }
    }

    /**
     * Graph answers code 100, subcode 33 both for a post deleted on the network and
     * for one the token can no longer read, so the post counts as deleted only while
     * the account's own node still reads with the same token. A story expires after
     * a day, so an older one is never reported as deleted.
     */
    private function deletedOnNetwork(AnalyticsPublication $publication): bool
    {
        if ($publication->content_type === PublicationContentType::Story
            && CarbonImmutable::now('UTC')->greaterThanOrEqualTo($publication->provider_published_at->addDay())) {
            return false;
        }

        $account = $this->account($publication);
        $graph = $this->graphBaseUrl($account);

        try {
            $this->get($account, "{$graph}/{$publication->remote_id}", ['fields' => 'id']);

            return false;
        } catch (AnalyticsCollectionException $exception) {
            if (! $exception->missing) {
                return false;
            }
        }

        try {
            $this->get($account, "{$graph}/{$this->accountNode($account)}", ['fields' => 'id']);

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
