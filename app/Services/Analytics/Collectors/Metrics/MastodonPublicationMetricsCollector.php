<?php

declare(strict_types=1);

namespace App\Services\Analytics\Collectors\Metrics;

use App\Dto\Analytics\PublicationMetricObservation;
use App\Enums\Analytics\MetricKey;
use App\Exceptions\Analytics\AnalyticsCollectionException;
use App\Models\AnalyticsPublication;
use App\Models\SocialAccount;
use App\Services\Social\Mastodon\MastodonApi;
use App\Support\Social\MastodonPoll;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

class MastodonPublicationMetricsCollector extends AbstractPublicationMetricsCollector
{
    /**
     * Statuses asked for in one multiple read: Mastodon's default page size,
     * since the endpoint documents no maximum. A thread's 24 replies take two.
     */
    private const int STATUSES_PER_READ = 20;

    public function collect(AnalyticsPublication $publication, CarbonImmutable $date): PublicationMetricObservation
    {
        $account = $this->account($publication);
        [$status, $threadPolls] = $this->read($account, $publication);

        return $this->observation($date, $this->withEngagements($this->present([
            $this->count(MetricKey::Reactions, $status, 'favourites_count'),
            $this->count(MetricKey::Comments, $status, 'replies_count'),
            $this->count(MetricKey::Shares, $status, 'reblogs_count'),
        ])), $this->pollMetadata(MastodonPoll::metadataFrom(data_get($status, 'poll')), $threadPolls));
    }

    /**
     * The status and its reply polls: a thread without a reply poll reads the
     * status alone; otherwise one read brings the status and its reply polls,
     * as on X. A server without that read (before Mastodon 4.3), or one that
     * leaves the status out (deleted or private), gets the status read alone,
     * which is where a deletion is confirmed.
     *
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function read(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $replyPolls = $this->replyPollIds($publication);

        if (filled($replyPolls)) {
            $thread = $this->readThread($account, $publication->remote_id, $replyPolls);

            if (filled($thread)) {
                return $thread;
            }
        }

        return [$this->readStatus($account, $publication), []];
    }

    /**
     * @return array<string, mixed>
     */
    private function readStatus(SocialAccount $account, AnalyticsPublication $publication): array
    {
        $response = $this->readUnlessDeleted(
            fn (): Response => $this->get(
                $account,
                MastodonApi::url($account->mastodon_instance, "statuses/{$publication->remote_id}"),
                authenticated: $account->canReadMastodonStatuses(),
            ),
            fn (AnalyticsCollectionException $exception): bool => $exception->gone && $this->confirmsDeletion($account),
            $publication->platform,
        );

        return (array) $response->json();
    }

    /**
     * The status and its reply polls (`GET /api/v1/statuses?id[]=`, Mastodon
     * 4.3), each reply poll's result with its reply position: one read for up
     * to `STATUSES_PER_READ` statuses, the status in the first. Null when the
     * server has no such read or left the status out; a read that failed only
     * for now is rethrown so the job retries.
     *
     * @param  array<int, string>  $replyPolls  status ids keyed by reply position
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}|null
     */
    private function readThread(SocialAccount $account, string $id, array $replyPolls): ?array
    {
        $chunks = collect([$id, ...$replyPolls])->chunk(self::STATUSES_PER_READ);
        $statuses = $this->readStatuses($account, $chunks->first()->values()->all());
        $status = $statuses->get($id);

        if (! is_array($status)) {
            return null;
        }

        foreach ($chunks->skip(1) as $ids) {
            $statuses = $statuses->union($this->readStatuses($account, $ids->values()->all()));
        }

        return [$status, $this->threadPolls($replyPolls, fn (string $replyId): mixed => data_get($statuses->get($replyId), 'poll'), MastodonPoll::class)];
    }

    /**
     * Statuses by id, from one multiple read; none when the server refuses it.
     *
     * @param  list<string>  $ids
     * @return Collection<string, mixed>
     */
    private function readStatuses(SocialAccount $account, array $ids): Collection
    {
        try {
            $response = $this->get(
                $account,
                MastodonApi::url($account->mastodon_instance, 'statuses', ['id[]' => $ids]),
                authenticated: $account->canReadMastodonStatuses(),
            );
        } catch (AnalyticsCollectionException $exception) {
            $this->rethrowWhenTemporary($exception);

            return collect();
        }

        return $response->collect()->keyBy('id');
    }

    /**
     * Mastodon answers 404 both for a deleted status and for a private one read without
     * its owner's token, so only an owner read counts, and only while the account's
     * statuses, the list discovery reads, still read with that token.
     */
    private function confirmsDeletion(SocialAccount $account): bool
    {
        return $account->canReadMastodonStatuses() && $this->stillReads(
            fn (): Response => $this->get($account, MastodonApi::url($account->mastodon_instance, "accounts/{$account->platform_user_id}/statuses"), ['limit' => 1]),
        );
    }
}
