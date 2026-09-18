<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;

test('seeder creates the two active plans and the archived legacy plan', function () {
    expect(Plan::count())->toBe(3);

    $socials = Plan::where('slug', Slug::Socials)->first();
    $workspaces = Plan::where('slug', Slug::Workspaces)->first();
    $legacy = Plan::where('slug', Slug::Workspace)->first();

    expect($socials->name)->toBe('Socials')
        ->and($socials->workspace_limit)->toBe(1)
        ->and($socials->is_archived)->toBeFalse()
        ->and($workspaces->name)->toBe('Workspaces')
        ->and($workspaces->workspace_limit)->toBeNull()
        ->and($workspaces->is_archived)->toBeFalse()
        ->and($legacy->name)->toBe('Workspace')
        ->and($legacy->workspace_limit)->toBe(1)
        ->and($legacy->is_archived)->toBeTrue();
});

test('seeder is idempotent', function () {
    $this->seed(PlanSeeder::class);

    expect(Plan::count())->toBe(3);
});

test('only the two new plans are active, ordered by sort', function () {
    $active = Plan::active()->orderBy('sort')->get();

    expect($active)->toHaveCount(2)
        ->and($active->first()->slug)->toBe(Slug::Socials)
        ->and($active->last()->slug)->toBe(Slug::Workspaces);
});
