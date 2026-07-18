<?php

declare(strict_types=1);

namespace App\Actions\Automation\Node;

use App\Actions\Automation\Run\AdvanceAutomationRun;
use App\DataTransferObjects\Automation\NodeRunResult;
use App\Enums\Automation\Run\Status as RunStatus;
use App\Models\AutomationNodeState;
use App\Models\AutomationRun;
use App\Services\Automation\ExpressionResolver;
use App\Services\Automation\FeedParser;
use App\Services\Brand\SafeHttpFetcher;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Fetches an RSS feed and progresses the run with the next-up unseen item.
 *
 *  - Filters by a per-node watermark stored in `automation_node_states`, so the
 *    very first run on an old feed processes none of the historical items.
 *  - When the fetch returns N new items, the current run takes item[0]; the
 *    remaining N-1 items are spawned as sibling runs that resume at the node
 *    immediately after this Fetch (with `context.fetched` already populated),
 *    so each item ends up generating its own Post / Webhook / etc.
 *  - When the feed yields no new items, the result short-circuits via the
 *    `no_items` handle; if the user hasn't wired anything to it, the run
 *    completes silently (handled by AdvanceAutomationRun's default branch).
 */
class RunFetchRssNode
{
    private const ITEM_HANDLE = 'default';

    private const NO_ITEMS_HANDLE = 'no_items';

    public function __construct(
        private ExpressionResolver $resolver,
        private SafeHttpFetcher $safeHttp,
        private AdvanceAutomationRun $advance,
        private FeedParser $parser,
    ) {}

    public function __invoke(AutomationRun $run, array $config): NodeRunResult
    {
        $feedUrl = $this->resolver->resolve((string) data_get($config, 'feed_url', ''), $run->resolverContext());

        if ($feedUrl === '') {
            return NodeRunResult::failed(__('automations.errors.fetch_rss_missing_url'));
        }

        // SafeHttpFetcher::get() re-validates every redirect hop against the SSRF
        // guard (not just the initial URL), so a public feed that 302s to an
        // internal host is never followed. It throws on a blocked hop, connection
        // failure, non-2xx status, or an excessive redirect chain — all of which
        // are legitimate "this feed couldn't be fetched" failures for this node.
        try {
            $response = $this->safeHttp->get($feedUrl);
        } catch (RuntimeException $e) {
            return NodeRunResult::failed(__('automations.errors.fetch_rss_request_failed'), [
                'message' => $e->getMessage(),
            ]);
        }

        $items = $this->parser->parse($response->body());

        if ($items === null) {
            return NodeRunResult::failed(__('automations.errors.fetch_rss_malformed'));
        }

        $nodeId = (string) $run->current_node_id;
        // Test runs (dry OR real-data) bypass the watermark entirely: they use an
        // epoch watermark so every item is treated as new, process only the first
        // item, and never spawn siblings or advance the watermark. This way a
        // test ALWAYS shows real data flowing through (instead of "no new items")
        // and never floods the feed or poisons the production watermark.
        $isPreview = $run->is_manual || $run->is_dry_run;
        $state = $isPreview ? null : AutomationNodeState::for($run->automation_id, $nodeId);
        $watermark = $isPreview
            ? CarbonImmutable::createFromTimestamp(0)
            : $this->parseWatermark(data_get($state->data, 'last_item_date'));

        [$newItems, $newestSeen] = $this->collectNewItems($items, $watermark);

        if ($state !== null && $newestSeen !== null) {
            $state->update(['data' => array_merge($state->data ?? [], [
                'last_item_date' => $newestSeen->toIso8601String(),
            ])]);
        }

        if ($newItems === []) {
            return NodeRunResult::completed(['fetch' => ['count' => 0]], nextHandle: self::NO_ITEMS_HANDLE);
        }

        $total = count($newItems);

        // A preview (manual/dry test) surfaces ONE item and never fans out, so it
        // shows the newest item — what the user expects to test against. A real run
        // takes the oldest new item and fans the rest out as siblings, preserving
        // feed chronology across branches (items are sorted oldest-first).
        if ($isPreview) {
            return NodeRunResult::completed([
                'fetch' => ['count' => $total, 'spawned' => 0],
                'fetched' => end($newItems),
            ]);
        }

        $first = array_shift($newItems);
        $this->spawnSiblings($run, $nodeId, $newItems);

        return NodeRunResult::completed([
            'fetch' => ['count' => $total, 'spawned' => count($newItems)],
            'fetched' => $first,
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $parsed  Normalized items from FeedParser.
     * @return array{0: list<array<string, mixed>>, 1: ?CarbonImmutable}
     */
    private function collectNewItems(array $parsed, CarbonImmutable $watermark): array
    {
        $items = [];
        $newestSeen = null;

        foreach ($parsed as $item) {
            $key = (string) data_get($item, 'key', '');
            if ($key === '') {
                continue;
            }

            $date = $this->parsePubDate((string) data_get($item, 'date', ''));
            if ($date === null) {
                continue;
            }

            if ($newestSeen === null || $date->greaterThan($newestSeen)) {
                $newestSeen = $date;
            }

            if (! $date->greaterThan($watermark)) {
                continue;
            }

            $item['_sort'] = $date->getTimestamp();
            $items[] = $item;
        }

        // Process oldest-first so siblings inherit a stable order matching feed chronology.
        usort($items, fn ($a, $b) => $a['_sort'] <=> $b['_sort']);

        // Drop the internal sort key — downstream nodes shouldn't see it.
        $items = array_map(function (array $item): array {
            unset($item['_sort']);

            return $item;
        }, $items);

        return [$items, $newestSeen];
    }

    private function spawnSiblings(AutomationRun $parent, string $fetchNodeId, array $items): void
    {
        if ($items === []) {
            return;
        }

        // Each remaining item gets its own run that fans out across EVERY branch
        // wired to the fetch node — matching how item[0] (the current run) fans
        // out, so no branch silently drops items 2..N.
        $targets = $this->advance->targetsFor($parent->automation, $fetchNodeId, self::ITEM_HANDLE);

        foreach ($items as $item) {
            $sibling = AutomationRun::create([
                'automation_id' => $parent->automation_id,
                'root_run_id' => $parent->rootId(),
                'trigger_item_id' => $parent->trigger_item_id,
                'generated_post_id' => $parent->generated_post_id,
                'is_manual' => $parent->is_manual,
                'is_dry_run' => $parent->is_dry_run,
                'status' => RunStatus::Pending,
                'context' => array_merge($parent->context ?? [], ['fetched' => $item]),
            ]);

            if ($targets === []) {
                $sibling->update(['status' => RunStatus::Completed, 'finished_at' => now()]);

                continue;
            }

            $this->advance->dispatchBranches($sibling, $targets);
        }
    }

    private function parseWatermark(?string $stored): CarbonImmutable
    {
        if ($stored === null) {
            return CarbonImmutable::now();
        }

        try {
            return CarbonImmutable::parse($stored);
        } catch (Throwable) {
            return CarbonImmutable::now();
        }
    }

    private function parsePubDate(string $raw): ?CarbonImmutable
    {
        if ($raw === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($raw);
        } catch (Throwable) {
            return null;
        }
    }
}
