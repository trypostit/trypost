<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('posts has an index led by status and scheduled_at for the due posts query', function () {
    expect(Schema::hasIndex('posts', ['status', 'scheduled_at']))->toBeTrue();
});

test('down drops the due posts index and up restores it', function () {
    $migration = require database_path('migrations/2026_10_06_091920_add_status_scheduled_at_index_to_posts_table.php');

    $migration->down();
    expect(Schema::hasIndex('posts', ['status', 'scheduled_at']))->toBeFalse();

    $migration->up();
    expect(Schema::hasIndex('posts', ['status', 'scheduled_at']))->toBeTrue();
});
