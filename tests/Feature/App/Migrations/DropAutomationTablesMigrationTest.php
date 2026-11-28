<?php

declare(strict_types=1);

use App\Enums\Post\CreatedVia;
use App\Models\Post;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->tables = [
        'automations' => '2026_05_22_211640_create_automations_table',
        'automation_trigger_items' => '2026_05_22_211641_create_automation_trigger_items_table',
        'automation_runs' => '2026_05_22_211642_create_automation_runs_table',
        'automation_node_runs' => '2026_05_22_211643_create_automation_node_runs_table',
        'automation_node_states' => '2026_05_23_164734_create_automation_node_states_table',
    ];

    foreach ($this->tables as $table => $migration) {
        if (! Schema::hasTable($table)) {
            (require database_path("migrations/{$migration}.php"))->up();
        }
    }
});

test('rewrites automation-created posts to web and drops the automation tables in FK order', function () {
    $post = Post::factory()->create(['created_via' => CreatedVia::Web]);
    DB::table('posts')->where('id', $post->id)->update(['created_via' => 'automation']);

    foreach (array_keys($this->tables) as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    $migration = require database_path('migrations/2026_09_05_124637_drop_automation_tables.php');
    $migration->up();

    expect($post->fresh()->created_via)->toBe(CreatedVia::Web);

    foreach (array_keys($this->tables) as $table) {
        expect(Schema::hasTable($table))->toBeFalse();
    }
});
