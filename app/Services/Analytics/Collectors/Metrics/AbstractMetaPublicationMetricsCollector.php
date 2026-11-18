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
use Illuminate\Support\Uri;

abstract class AbstractMetaPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    /** @var list<string> */
    protected array $refusals = [];

    /** Whether an optional read was refused with Graph's "object does not exist" answer. */
    protected bool $refusedMissingObject = false;

    abstract protected function read(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation;

    /**
     * The account's list of its own posts, the edge its discovery reads: reading it
     * proves the token still reads the account's posts.
     */
    abstract protected function postsEdge(): string;

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $this->refusals = [];
        $this->refusedMissingObject = false;

        return $this->readUnlessDeleted(
            fn (): PublicationMetricObservation => $this->read($publication, $date),
            fn (AnalyticsCollectionException $exception): bool => $this->mayBeDeleted($exception, $publication) && $this->confirmsDeletion($publication),
            $publication->platform,
        );
    }

    /**
     * A read that failed as malformed, or every read refused for permission while one
     * of them answered "object does not exist", may hide a deleted post. Otherwise the
     * failure stands as it is.
     */
    protected function mayBeDeleted(AnalyticsCollectionException $exception, AnalyticsPublication $publication): bool
    {
        return match ($exception->category) {
            'malformed' => $exception->missingObject || $this->refusedMissingObject,
            'permission' => $this->refusedMissingObject,
            default => false,
        };
    }

    /**
     * The post counts as deleted only when Graph says it does not exist (see
     * MetaAnalyticsResponse) while the account's posts still read with the same
     * token. A story is never checked: it disappears on its own when it expires.
     */
    private function confirmsDeletion(AnalyticsPublication $publication): bool
    {
        if ($publication->content_type === PublicationContentType::Story) {
            return false;
        }

        $account = $this->account($publication);
        $graph = $account->platform->graphApiUrl();

        return $this->readsMissing(
            fn (): Response => $this->readPost($account, $publication, $graph),
            fn (AnalyticsCollectionException $exception): bool => $exception->missingObject,
        ) && $this->stillReads(
            fn (): Response => $this->get($account, "{$graph}/{$account->platform_user_id}/{$this->postsEdge()}", ['fields' => 'id', 'limit' => '1']),
        );
    }

    /**
     * The read that answers "object does not exist" once the post is gone.
     */
    protected function readPost(SocialAccount $account, AnalyticsPublication $publication, string $graph): Response
    {
        return $this->get($account, "{$graph}/{$publication->remote_id}", ['fields' => 'id']);
    }

    /**
     * Keeps a read the token was refused (its category, and whether the object
     * was missing) and logs it under `$event`.
     *
     * @param  array<string, mixed>  $context
     */
    private function recordRefusal(AnalyticsCollectionException $exception, string $event, array $context): void
    {
        $this->refusals[] = $exception->category;
        $this->refusedMissingObject = $this->refusedMissingObject || $exception->missingObject;

        Log::info($event, [...$context, 'category' => $exception->category, 'reason' => $exception->getMessage()]);
    }

    /**
     * An optional read fails for good only when the token was not refused it
     * (a field its permissions do not cover, or one Meta calls malformed).
     */
    private function rethrowUnlessRefused(AnalyticsCollectionException $exception): void
    {
        if (! collect(['permission', 'malformed'])->containsStrict($exception->category)) {
            throw $exception;
        }
    }

    protected function successfulResponse(Response $response): Response
    {
        return MetaAnalyticsResponse::successful($response, 'publication metrics collection');
    }

    /**
     * A read the token may be refused (a field its permissions do not cover):
     * a refusal is recorded and reads as nothing, so the metrics already
     * measured are kept; any other failure is rethrown.
     *
     * @param  array<string, string>  $query
     * @return array<string, mixed>
     */
    protected function optional(SocialAccount $account, string $url, array $query): array
    {
        try {
            return (array) $this->get($account, $url, $query)->json();
        } catch (AnalyticsCollectionException $exception) {
            $this->rethrowUnlessRefused($exception);
            $this->recordRefusal($exception, "analytics.{$account->platform->value}_read_refused", ['path' => Uri::of($url)->path()]);

            return [];
        }
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
            $this->rethrowUnlessRefused($exception);

            if (count($metrics) > 1) {
                return array_merge(...array_map(
                    fn (string $metric): array => $this->optionalInsightItems($account, $url, [$metric], $query),
                    $metrics,
                ));
            }

            $this->recordRefusal($exception, 'analytics.meta_metric_rejected', [
                'platform' => $account->platform->value,
                'path' => Uri::of($url)->path(),
                'metric' => data_get($metrics, 0),
            ]);

            return [];
        }

        return collect($response->json('data'))->filter(fn (mixed $item): bool => is_array($item))->values()->all();
    }
}
