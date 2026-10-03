<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const DROPPED_BRAND_COLUMNS = [
    'brand_website', 'brand_description', 'brand_voice_traits',
    'brand_color', 'background_color', 'text_color',
    'brand_font', 'image_style', 'content_language',
];

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_09_30_141929_drop_brand_columns_from_workspaces_table.php');
    $this->seededWorkspaceIds = [];

    if (! Schema::hasColumn('workspaces', 'brand_description')) {
        $this->migration->down();
    }
});

afterEach(function () {
    if (Schema::hasColumn('workspaces', 'brand_description')) {
        $this->migration->up();
    }

    $workspaces = DB::table('workspaces')->whereIn('id', $this->seededWorkspaceIds);
    $userIds = (clone $workspaces)->pluck('user_id')->all();
    $accountIds = (clone $workspaces)->pluck('account_id')->all();

    DB::table('workspaces')->whereIn('id', $this->seededWorkspaceIds)->delete();
    DB::table('users')->whereIn('id', $userIds)->delete();
    DB::table('accounts')->whereIn('id', $accountIds)->delete();
});

function seedBrandedWorkspace(object $test, array $attributes = []): string
{
    $user = User::factory()->create();
    $id = (string) Str::uuid7();

    DB::table('workspaces')->insert([
        'id' => $id,
        'account_id' => $user->account_id,
        'user_id' => $user->id,
        'name' => 'Branded',
        'created_at' => now(),
        'updated_at' => now(),
        ...$attributes,
    ]);
    $test->seededWorkspaceIds[] = $id;

    return $id;
}

test('drops every brand column and keeps the workspace row', function () {
    $id = seedBrandedWorkspace($this, [
        'brand_description' => 'We sell coffee',
        'content_language' => 'fr',
    ]);

    $this->migration->up();

    foreach (DROPPED_BRAND_COLUMNS as $column) {
        expect(Schema::hasColumn('workspaces', $column))->toBeFalse();
    }

    expect(DB::table('workspaces')->where('id', $id)->value('name'))->toBe('Branded');
});

test('down restores all nine columns with the content language default', function () {
    $this->migration->up();
    $this->migration->down();

    foreach (DROPPED_BRAND_COLUMNS as $column) {
        expect(Schema::hasColumn('workspaces', $column))->toBeTrue();
    }

    $id = seedBrandedWorkspace($this);

    expect(DB::table('workspaces')->where('id', $id)->value('content_language'))->toBe('en');
});
