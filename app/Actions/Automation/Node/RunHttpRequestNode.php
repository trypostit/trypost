<?php

declare(strict_types=1);

namespace App\Actions\Automation\Node;

use App\Actions\Automation\Run\AdvanceAutomationRun;
use App\DataTransferObjects\Automation\NodeRunResult;
use App\Enums\Automation\AuthType;
use App\Enums\Automation\HttpMethod;
use App\Enums\Automation\Run\Status as RunStatus;
use App\Models\AutomationNodeState;
use App\Models\AutomationRun;
use App\Services\Automation\ExpressionResolver;
use App\Services\Brand\SafeHttpFetcher;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Generalized HTTP node — supersedes the old `fetch_json` polling-only node.
 *
 * Two execution modes share one config:
 *  - **Single request mode**: the response is a single object (or `items_path`
 *    resolves to nothing), so the parsed body is set on `context.fetched` as a
 *    single payload. Useful for enrichment ("look up user X before generating").
 *  - **List mode**: the response is a collection, so each item drives its own
 *    downstream branch. The collection is found by, in order:
 *      1. `items_path` (dot notation, e.g. `data.items`; `*` iterates a top-level
 *         object map's values),
 *      2. an empty `items_path` + a top-level JSON array,
 *      3. an empty `items_path` + an NDJSON (one JSON object per line) body.
 *    The current run takes the first new item; the rest spawn sibling runs.
 *
 * New items are detected (so a feed isn't reprocessed every poll) by, in order:
 *  - `item_date_path` — a per-node date watermark (cheapest, bounded), or
 *  - `item_key_path` — a per-node set of seen keys (covers ids/uuids/urls).
 * Either way the FIRST poll records the baseline and emits nothing, so pointing
 * the node at an existing feed never floods on day one.
 *
 * Auth types: none, bearer, basic, api_key. Credentials are stored encrypted
 * on the Automation model (see `Automation::booted()`) and decrypted here.
 */
class RunHttpRequestNode
{
    private const ITEM_HANDLE = 'default';

    private const NO_ITEMS_HANDLE = 'no_items';

    /**
     * Upper bound on the per-node seen-key history. FIFO-evicts the oldest keys
     * once exceeded — matching n8n's capped "history size" for its dedup store.
     */
    private const MAX_SEEN_KEYS = 500;

    public function __construct(
        private ExpressionResolver $resolver,
        private SafeHttpFetcher $safeHttp,
        private AdvanceAutomationRun $advance,
    ) {}

    public function __invoke(AutomationRun $run, array $config): NodeRunResult
    {
        $url = (string) data_get($config, 'url', '');
        $method = strtoupper((string) data_get($config, 'method', HttpMethod::Get->value));
        $nodeId = (string) $run->current_node_id;
        $context = $run->resolverContext();

        if ($url === '') {
            return NodeRunResult::failed(__('automations.errors.http_missing_url'));
        }

        $resolvedUrl = $this->resolver->resolve($url, $context);

        try {
            $this->safeHttp->guardAgainstSsrf($resolvedUrl);
        } catch (RuntimeException) {
            return NodeRunResult::failed(__('automations.errors.url_not_allowed'), [
                'reason' => 'url_not_allowed',
                'url' => $resolvedUrl,
            ]);
        }

        $request = $this->buildRequest($config, $context);
        $jsonBody = $this->buildJsonBody($method, $config, $context);

        try {
            $response = match (HttpMethod::tryFrom($method)) {
                HttpMethod::Get => $request->get($resolvedUrl),
                HttpMethod::Delete => $request->delete($resolvedUrl),
                HttpMethod::Post => $request->post($resolvedUrl, $jsonBody),
                HttpMethod::Put => $request->put($resolvedUrl, $jsonBody),
                HttpMethod::Patch => $request->patch($resolvedUrl, $jsonBody),
                default => null,
            };
        } catch (Throwable $e) {
            return NodeRunResult::failed(__('automations.errors.http_request_exception'), ['message' => $e->getMessage()]);
        }

        if ($response === null) {
            return NodeRunResult::failed("Unsupported HTTP method: {$method}");
        }

        if (! $response->successful()) {
            return NodeRunResult::failed(__('automations.errors.http_request_failed'), [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);
        }

        $payload = $this->decodeBody($response);
        $itemsPath = is_string($raw = data_get($config, 'items_path')) ? trim($raw) : '';

        // No path + a top-level array (or NDJSON list) → iterate it. No path + a
        // single object/scalar → single-response mode: forward the whole body.
        if ($itemsPath === '') {
            if (! is_array($payload) || ! array_is_list($payload)) {
                return NodeRunResult::completed([
                    'fetch' => ['count' => 1, 'spawned' => 0],
                    'fetched' => $payload,
                ]);
            }

            return $this->processItems($run, $nodeId, $config, $payload);
        }

        // Explicit path: dot notation, or `*` to iterate a top-level object map.
        $resolved = data_get($payload, $itemsPath);

        if (! is_array($resolved)) {
            return NodeRunResult::failed(__('automations.errors.http_items_path_not_array'));
        }

        return $this->processItems($run, $nodeId, $config, array_values($resolved));
    }

    /**
     * Decodes the response as JSON, falling back to NDJSON (one JSON value per
     * line) so streaming/log-style list endpoints work too.
     */
    private function decodeBody(Response $response): mixed
    {
        $json = $response->json();

        if (is_array($json)) {
            return $json;
        }

        return $this->parseNdjson($response->body()) ?? $json;
    }

    /**
     * @return array<int, mixed>|null The decoded list, or null when the body is
     *                                not newline-delimited JSON.
     */
    private function parseNdjson(string $body): ?array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($body)) ?: [];
        $items = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $decoded = json_decode($line, true);
            if ($decoded === null && $line !== 'null') {
                return null;
            }

            $items[] = $decoded;
        }

        return count($items) > 1 ? $items : null;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<int, mixed>  $items
     */
    private function processItems(AutomationRun $run, string $nodeId, array $config, array $items): NodeRunResult
    {
        $itemKeyPath = is_string($k = data_get($config, 'item_key_path')) ? trim($k) : '';
        $itemDatePath = is_string($d = data_get($config, 'item_date_path')) ? trim($d) : '';

        // Test runs (dry OR real-data) walk a single item so the user sees data
        // flow, without spawning siblings, advancing watermarks or recording keys.
        if ($run->is_manual || $run->is_dry_run) {
            if ($items === []) {
                return NodeRunResult::completed(['fetch' => ['count' => 0]], nextHandle: self::NO_ITEMS_HANDLE);
            }

            return NodeRunResult::completed([
                'fetch' => ['count' => count($items), 'spawned' => 0],
                'fetched' => $items[0],
            ]);
        }

        // A date watermark is cheapest and bounded, so it wins when both are set;
        // the seen-key set is the fallback for feeds without a usable date.
        $newItems = match (true) {
            $itemDatePath !== '' => $this->filterByDate($run->automation_id, $nodeId, $items, $itemDatePath),
            $itemKeyPath !== '' => $this->filterBySeenKeys($run->automation_id, $nodeId, $items, $itemKeyPath),
            default => $items,
        };

        if ($newItems === []) {
            return NodeRunResult::completed(['fetch' => ['count' => 0]], nextHandle: self::NO_ITEMS_HANDLE);
        }

        $first = array_shift($newItems);
        $this->spawnSiblings($run, $nodeId, $newItems);

        return NodeRunResult::completed([
            'fetch' => ['count' => count($newItems) + 1, 'spawned' => count($newItems)],
            'fetched' => $first,
        ]);
    }

    /**
     * Keeps only items newer than the per-node date watermark, then advances the
     * watermark to the newest date seen. The first poll records the baseline and
     * emits nothing, so an existing feed never floods on day one.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function filterByDate(string $automationId, string $nodeId, array $items, string $datePath): array
    {
        $state = AutomationNodeState::for($automationId, $nodeId);
        $isFirstPoll = ! array_key_exists('last_item_date', (array) $state->data);
        $watermark = $this->parseWatermark(data_get($state->data, 'last_item_date'));
        $newest = null;
        $new = [];

        foreach ($items as $item) {
            $date = $this->parseDate(data_get($item, $datePath));
            if ($date === null) {
                continue;
            }

            if ($newest === null || $date->greaterThan($newest)) {
                $newest = $date;
            }

            // The first poll only records the baseline (the newest item's date),
            // emitting nothing — so an existing feed never floods on day one, even
            // if some items are dated slightly ahead of the server clock.
            if (! $isFirstPoll && $date->greaterThan($watermark)) {
                $new[] = $item;
            }
        }

        if ($newest !== null) {
            $state->update(['data' => array_merge($state->data ?? [], [
                'last_item_date' => $newest->toIso8601String(),
            ])]);
        }

        return $new;
    }

    /**
     * Keeps only items whose key hasn't been seen before, recording the new keys
     * in a FIFO-capped per-node set. The first poll records every key but emits
     * nothing (baseline), matching the date-watermark semantics so pointing the
     * node at an existing feed never floods on day one.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, mixed>
     */
    private function filterBySeenKeys(string $automationId, string $nodeId, array $items, string $keyPath): array
    {
        $state = AutomationNodeState::for($automationId, $nodeId);
        $isFirstPoll = ! array_key_exists('seen_keys', (array) $state->data);
        $hashes = array_values((array) data_get($state->data, 'seen_keys', []));
        $seen = array_flip($hashes);
        $new = [];

        foreach ($items as $item) {
            $hash = $this->keyHash($item, $keyPath);
            if (isset($seen[$hash])) {
                continue;
            }

            $seen[$hash] = true;
            $hashes[] = $hash;

            if (! $isFirstPoll) {
                $new[] = $item;
            }
        }

        if (count($hashes) > self::MAX_SEEN_KEYS) {
            $hashes = array_slice($hashes, count($hashes) - self::MAX_SEEN_KEYS);
        }

        $state->update(['data' => array_merge((array) $state->data, ['seen_keys' => $hashes])]);

        return $new;
    }

    /**
     * Stable hash for an item's dedup key. Objects use `item_key_path` (falling
     * back to the whole item); scalars use their own value. Hashing keeps the
     * stored set compact and avoids persisting raw payloads.
     */
    private function keyHash(mixed $item, string $keyPath): string
    {
        if (is_array($item)) {
            $key = $keyPath !== '' ? data_get($item, $keyPath) : null;
            // Fall back to the whole item when no usable key is present; serialize
            // guards against json_encode returning false on malformed UTF-8.
            $key = ($key === null || $key === '') ? (json_encode($item) ?: serialize($item)) : (string) $key;
        } else {
            $key = (string) $item;
        }

        return md5($key);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     */
    private function buildRequest(array $config, array $context): PendingRequest
    {
        $request = Http::asJson();

        $headers = [];
        foreach ((array) data_get($config, 'headers', []) as $k => $v) {
            $headers[$k] = $this->resolver->resolve((string) $v, $context);
        }

        $authType = AuthType::tryFrom((string) data_get($config, 'auth_type', AuthType::None->value));
        if ($authType === AuthType::Bearer) {
            $token = $this->decrypt((string) data_get($config, 'auth_token', ''));
            if ($token !== '') {
                $request = $request->withToken($this->resolver->resolve($token, $context));
            }
        } elseif ($authType === AuthType::Basic) {
            $user = (string) data_get($config, 'auth_username', '');
            $pass = $this->decrypt((string) data_get($config, 'auth_password', ''));
            if ($user !== '' || $pass !== '') {
                $request = $request->withBasicAuth(
                    $this->resolver->resolve($user, $context),
                    $this->resolver->resolve($pass, $context),
                );
            }
        } elseif ($authType === AuthType::ApiKey) {
            $headerName = (string) data_get($config, 'auth_header_name', 'X-API-Key');
            $token = $this->decrypt((string) data_get($config, 'auth_token', ''));
            if ($token !== '') {
                $headers[$headerName] = $this->resolver->resolve($token, $context);
            }
        }

        if ($headers !== []) {
            $request = $request->withHeaders($headers);
        }

        return $request
            ->withUserAgent(config('trypost.user_agent'))
            ->withOptions($this->safeHttp->redirectGuardOptions());
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildJsonBody(string $method, array $config, array $context): array
    {
        if (! in_array(HttpMethod::tryFrom($method), HttpMethod::withBody(), true)) {
            return [];
        }

        $template = (string) data_get($config, 'body_template', '');
        if ($template === '') {
            return [];
        }

        // Parse the JSON body template first, then resolve placeholders in its
        // string leaves so data containing quotes/newlines can't corrupt it.
        $decodedTemplate = json_decode($template, true);

        if (! is_array($decodedTemplate)) {
            return [];
        }

        return $this->resolver->resolveStructured($decodedTemplate, $context);
    }

    /**
     * @param  array<int, mixed>  $items
     */
    private function spawnSiblings(AutomationRun $parent, string $fetchNodeId, array $items): void
    {
        if ($items === []) {
            return;
        }

        // Each remaining item gets its own run that fans out across EVERY branch
        // wired to this node — matching how item[0] (the current run) fans out.
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

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            return Crypt::decryptString($value);
        } catch (Throwable) {
            // Value isn't an encrypted payload (legacy plain text or already decrypted).
            return $value;
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

    private function parseDate(mixed $raw): ?CarbonImmutable
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }
}
