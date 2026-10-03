<?php

use App\Enums\User\Locale;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idea_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('name', 60);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['workspace_id', 'position']);
        });

        $this->backfillDefaultStages();
    }

    public function down(): void
    {
        Schema::dropIfExists('idea_stages');
    }

    public function backfillDefaultStages(): void
    {
        DB::table('workspaces')
            ->leftJoin('users', 'users.id', '=', 'workspaces.user_id')
            ->select('workspaces.id', 'users.locale')
            ->chunkById(100, function (Collection $workspaces): void {
                $seeded = DB::table('idea_stages')
                    ->whereIn('workspace_id', $workspaces->pluck('id'))
                    ->distinct()
                    ->pluck('workspace_id')
                    ->all();

                $now = now();
                $rows = [];

                foreach ($workspaces->whereNotIn('id', $seeded) as $workspace) {
                    $locale = $workspace->locale ?: Locale::DEFAULT->value;

                    foreach (['todo', 'in_progress', 'done'] as $position => $key) {
                        $rows[] = [
                            'id' => (string) Str::uuid7(),
                            'workspace_id' => $workspace->id,
                            'name' => __("create.ideas.default_stages.{$key}", [], $locale),
                            'position' => $position,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    DB::table('idea_stages')->insert($rows);
                }
            }, 'workspaces.id', 'id');
    }
};
