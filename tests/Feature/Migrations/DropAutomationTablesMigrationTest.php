<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('rewrites automation-created posts to web and drops the automation tables', function () {
    $post = Post::factory()->create(['created_via' => CreatedVia::Web]);
    DB::table('posts')->where('id', $post->id)->update(['created_via' => 'automation']);

    $migration = require database_path('migrations/2026_09_05_124637_drop_automation_tables.php');
    $migration->up();

    expect($post->fresh()->created_via)->toBe(CreatedVia::Web);

    foreach (['automations', 'automation_trigger_items', 'automation_runs', 'automation_node_runs', 'automation_node_states'] as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});
