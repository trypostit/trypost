<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_09_30_150428_drop_workspace_ai_usages_table.php');
});

afterEach(function () {
    if (Schema::hasTable('workspace_ai_usages')) {
        $this->migration->up();
    }
});

test('drops the workspace ai usages table', function () {
    if (! Schema::hasTable('workspace_ai_usages')) {
        $this->migration->down();
    }

    $this->migration->up();

    expect(Schema::hasTable('workspace_ai_usages'))->toBeFalse();
});

test('down recreates the table with its original columns', function () {
    $this->migration->up();
    $this->migration->down();

    expect(Schema::hasColumns('workspace_ai_usages', [
        'id', 'account_id', 'workspace_id', 'user_id', 'post_id', 'type', 'provider', 'model',
        'prompt_tokens', 'completion_tokens', 'total_tokens', 'credits', 'metadata',
        'created_at', 'updated_at',
    ]))->toBeTrue();
});
