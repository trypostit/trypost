<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;

test('the inbox tree, prune and publication lookups are indexed on every engine', function (string $table, array $columns) {
    expect(Schema::hasIndex($table, $columns))->toBeTrue();
})->with([
    'message parent' => ['inbox_messages', ['parent_id']],
    'message root' => ['inbox_messages', ['root_id']],
    'message prune' => ['inbox_messages', ['platform_created_at']],
    'conversation publication' => ['inbox_conversations', ['analytics_publication_id']],
]);
