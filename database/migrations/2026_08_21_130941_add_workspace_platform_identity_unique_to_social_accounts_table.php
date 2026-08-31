<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private int $rewrittenAutomations = 0;

    public function up(): void
    {
        $this->collapseDuplicateIdentities();

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->unique(
                ['workspace_id', 'platform', 'platform_user_id'],
                'social_accounts_workspace_platform_identity_unique',
            );
        });
    }

    /**
     * Drops the index only. The data merge in `up()` is one-way: the losing
     * rows are gone, so rolling back leaves the collapsed identities collapsed.
     * Every merge is logged at warning level so it can be reconstructed.
     *
     * `social_accounts.workspace_id` carries a foreign key, and the composite
     * unique is the only index covering it - as its leftmost prefix. MySQL
     * refuses to drop the sole index backing a foreign key, so the constraint
     * needs another one to rest on first. PostgreSQL has no such requirement
     * and simply carries the extra index.
     */
    public function down(): void
    {
        if (! Schema::hasIndex('social_accounts', 'social_accounts_workspace_id_index')) {
            Schema::table('social_accounts', function (Blueprint $table) {
                $table->index('workspace_id');
            });
        }

        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropUnique('social_accounts_workspace_platform_identity_unique');
        });
    }

    /**
     * Installs that predate the unique index could store the same identity twice
     * (the network guard was bypassed for multi-account installs, and Pinterest
     * always created a fresh row). Keep the newest row per identity, move
     * everything that points at the losers over to it, and drop them.
     */
    private function collapseDuplicateIdentities(): void
    {
        $duplicates = DB::table('social_accounts')
            ->select('workspace_id', 'platform', 'platform_user_id')
            ->groupBy('workspace_id', 'platform', 'platform_user_id')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $ids = $this->newestFirst(
                DB::table('social_accounts')
                    ->where('workspace_id', $duplicate->workspace_id)
                    ->where('platform', $duplicate->platform)
                    ->where('platform_user_id', $duplicate->platform_user_id)
            )->pluck('id')->all();

            $keepId = array_shift($ids);

            if ($keepId === null || $ids === []) {
                continue;
            }

            $repointed = DB::table('post_platforms')
                ->whereIn('social_account_id', $ids)
                ->update(['social_account_id' => $keepId]);

            $this->rewrittenAutomations = 0;
            $this->repointAutomations((string) $duplicate->workspace_id, $ids, $keepId);

            DB::table('social_accounts')->whereIn('id', $ids)->delete();

            $dropped = $this->dropRepeatedPostTargets($keepId);

            // Self-hosted installs run this unattended and it cannot be undone,
            // so leave enough behind to reconstruct what happened.
            Log::warning('Collapsed duplicate social accounts', [
                'workspace_id' => $duplicate->workspace_id,
                'platform' => $duplicate->platform,
                'platform_user_id' => $duplicate->platform_user_id,
                'kept_id' => $keepId,
                'dropped_ids' => $ids,
                'post_platforms_repointed' => $repointed,
                'post_platforms_deleted' => $dropped,
                'automations_rewritten' => $this->rewrittenAutomations,
            ]);
        }
    }

    /**
     * Newest wins, with a total ordering so a rehearsal on a replica and the
     * real run keep the same row. A null `created_at` sorts oldest on every
     * engine rather than first on Postgres and last on MySQL.
     */
    private function newestFirst(QueryBuilder $query): QueryBuilder
    {
        return $query
            ->orderByRaw('case when created_at is null then 1 else 0 end')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * A post could hold one row per duplicate account. Once they all point at
     * the surviving account the post would publish to it once per row.
     *
     * Published rows are never touched: they record a post that is live on the
     * network and carry the `platform_post_id` needed to manage it later, and
     * two duplicate accounts really could each have published. Only the
     * unpublished repeats collapse, preferring the row the user enabled -
     * SyncPostPlatforms seeds a disabled row for every account in the
     * workspace, so the usual duplicate is one row the user checked next to one
     * they never saw, both pending and created in the same second. Keeping the
     * disabled one would silently stop a scheduled post reaching that account.
     */
    private function dropRepeatedPostTargets(string $keepId): int
    {
        $deleted = 0;

        $repeated = DB::table('post_platforms')
            ->select('post_id')
            ->where('social_account_id', $keepId)
            ->groupBy('post_id')
            ->havingRaw('count(*) > 1')
            ->pluck('post_id');

        foreach ($repeated as $postId) {
            $target = fn (): QueryBuilder => DB::table('post_platforms')
                ->where('social_account_id', $keepId)
                ->where('post_id', $postId);

            $ids = $this->newestFirst(
                $target()
                    ->where('status', '!=', 'published')
                    ->orderByRaw('case when enabled then 0 else 1 end')
            )->pluck('id')->all();

            // With a published row the content already went out, so every
            // unpublished repeat is a second delivery waiting to happen -
            // PostPlatform::scopeEnabled() filters on `enabled` alone.
            if (! $target()->where('status', 'published')->exists()) {
                array_shift($ids);
            }

            if ($ids !== []) {
                $deleted += DB::table('post_platforms')->whereIn('id', $ids)->delete();
            }
        }

        return $deleted;
    }

    /**
     * Automation nodes persist `social_account_id` inside a JSON column with no
     * foreign key, so a dropped account leaves the node pointing at nothing and
     * RunGenerateNode quietly skips that target. Rewrite the ids and drop the
     * entries that collapsing just turned into duplicates.
     *
     * @param  array<int, string>  $droppedIds
     */
    private function repointAutomations(string $workspaceId, array $droppedIds, string $keepId): void
    {
        $automations = DB::table('automations')
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('nodes')
            ->get(['id', 'nodes']);

        foreach ($automations as $automation) {
            $nodes = json_decode((string) $automation->nodes, true);

            if (! is_array($nodes)) {
                continue;
            }

            $replaced = $this->replaceAccountIds($nodes, $droppedIds, $keepId);

            if ($replaced === $nodes) {
                continue;
            }

            DB::table('automations')
                ->where('id', $automation->id)
                ->update(['nodes' => json_encode($this->dedupeAccountEntries($replaced))]);

            $this->rewrittenAutomations++;
        }
    }

    /**
     * Account ids are UUIDs, so matching on the value covers both the current
     * `accounts[].social_account_id` shape and the legacy `social_account_ids`
     * list without having to know where either sits in the tree.
     *
     * @param  array<mixed>  $nodes
     * @param  array<int, string>  $droppedIds
     * @return array<mixed>
     */
    private function replaceAccountIds(array $nodes, array $droppedIds, string $keepId): array
    {
        array_walk_recursive($nodes, function (mixed &$value) use ($droppedIds, $keepId): void {
            if (is_string($value) && in_array($value, $droppedIds, true)) {
                $value = $keepId;
            }
        });

        return $nodes;
    }

    /**
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private function dedupeAccountEntries(array $value): array
    {
        foreach ($value as $key => $child) {
            if (! is_array($child)) {
                continue;
            }

            $value[$key] = $this->dedupeAccountEntries($child);
        }

        if (isset($value['accounts']) && is_array($value['accounts'])) {
            $value['accounts'] = array_values(collect($value['accounts'])
                ->unique(fn (mixed $entry): string => (string) data_get($entry, 'social_account_id', ''))
                ->all());
        }

        if (isset($value['social_account_ids']) && is_array($value['social_account_ids'])) {
            $value['social_account_ids'] = array_values(array_unique($value['social_account_ids']));
        }

        return $value;
    }
};
