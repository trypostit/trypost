<?php

declare(strict_types=1);

use App\Enums\Plan\Slug;
use App\Models\Plan;

test('plan can be created with factory', function () {
    Plan::where('slug', Slug::Workspace)->delete();

    $plan = Plan::factory()->create([
        'slug' => Slug::Workspace,
        'name' => 'Workspace',
    ]);

    expect($plan)->toBeInstanceOf(Plan::class)
        ->and($plan->slug)->toBe(Slug::Workspace)
        ->and($plan->name)->toBe('Workspace')
        ->and($plan->is_archived)->toBeFalse();
});

test('plan slug is cast to enum', function () {
    $plan = Plan::where('slug', Slug::Workspace)->first();

    expect($plan->slug)->toBeInstanceOf(Slug::class)
        ->and($plan->slug)->toBe(Slug::Workspace)
        ->and($plan->slug->label())->toBe('Workspace');
});

test('active scope excludes archived plans', function () {
    $activeBefore = Plan::active()->count();

    $plan = Plan::where('slug', Slug::Socials)->first();
    $plan->update(['is_archived' => true]);

    expect(Plan::active()->count())->toBe($activeBefore - 1);
});

test('integer fields are cast correctly', function () {
    $plan = Plan::where('slug', Slug::Socials)->first();

    expect($plan->workspace_limit)->toBeInt()
        ->and($plan->sort)->toBeInt();
});

test('an unlimited plan stores a null workspace limit', function () {
    $plan = Plan::where('slug', Slug::Workspaces)->first();

    expect($plan->workspace_limit)->toBeNull();
});
