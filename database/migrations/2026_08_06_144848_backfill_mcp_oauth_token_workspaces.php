<?php

declare(strict_types=1);

use App\Actions\AccessToken\RevokeAccessTokens;
use App\Models\AccessToken;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Assign existing MCP OAuth tokens (workspace_id null) to a workspace, or revoke
 * them when no confident mapping exists. Runs in a single transaction so a
 * failure leaves no partially backfilled rows.
 *
 * Only touches live/recoverable MCP sessions (AccessToken::connectedMcpOAuth).
 * Mapping is conservative: a single account membership binds automatically;
 * multiple memberships always revoke so the client reconnects with an explicit
 * workspace pick (never guess from current_workspace_id).
 */
return new class extends Migration
{
    /**
     * Test seam: invoked after all chunks and before commit.
     *
     * @var (callable(): void)|null
     */
    public $beforeCommit = null;

    public function up(): void
    {
        DB::beginTransaction();

        try {
            AccessToken::query()
                ->connectedMcpOAuth()
                ->whereNull('workspace_id')
                ->orderBy('id')
                ->chunkById(100, function (Collection $tokens): void {
                    $users = User::query()
                        ->whereIn('id', $tokens->pluck('user_id')->unique()->filter()->all())
                        ->with('workspaces')
                        ->get()
                        ->keyBy('id');

                    $toRevoke = collect();

                    foreach ($tokens as $token) {
                        $workspaceId = $this->resolveWorkspaceId(
                            $users->get($token->user_id),
                        );

                        if ($workspaceId === null) {
                            $toRevoke->push($token);

                            continue;
                        }

                        $token->forceFill(['workspace_id' => $workspaceId])->saveQuietly();
                    }

                    if ($toRevoke->isNotEmpty()) {
                        RevokeAccessTokens::execute($toRevoke);
                    }
                });

            if (is_callable($this->beforeCommit)) {
                ($this->beforeCommit)();
            }

            DB::commit();
        } catch (Throwable $e) {
            DB::rollBack();

            throw $e;
        }
    }

    public function down(): void
    {
        // Irreversible data migration — bound tokens keep their workspace_id.
    }

    private function resolveWorkspaceId(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        $accountWorkspaces = $user->workspaces
            ->where('account_id', $user->account_id)
            ->sortBy('created_at')
            ->values();

        if ($accountWorkspaces->count() !== 1) {
            return null;
        }

        return (string) $accountWorkspaces->first()->id;
    }
};
