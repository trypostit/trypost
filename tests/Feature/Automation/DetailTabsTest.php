<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['account_id' => $this->user->account_id]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Admin->value]);
    $this->user->refresh();
    $this->automation = Automation::factory()->for($this->workspace)->create();
});

it('redirects a draft automation URL to the workflow tab', function () {
    $this->actingAs($this->user)
        ->get(route('app.automations.show', $this->automation->id))
        ->assertRedirect(route('app.automations.workflow', $this->automation->id));
});

it('redirects a live automation URL to the metrics tab', function () {
    $active = Automation::factory()->for($this->workspace)->active()->create();

    $this->actingAs($this->user)
        ->get(route('app.automations.show', $active->id))
        ->assertRedirect(route('app.automations.metrics', $active->id));

    $paused = Automation::factory()->for($this->workspace)->paused()->create();

    $this->actingAs($this->user)
        ->get(route('app.automations.show', $paused->id))
        ->assertRedirect(route('app.automations.metrics', $paused->id));
});

it('renders the workflow editor on the workflow tab', function () {
    $this->actingAs($this->user)
        ->get(route('app.automations.workflow', $this->automation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('automations/Form'));
});

it('renders the settings tab', function () {
    $this->actingAs($this->user)
        ->get(route('app.automations.settings', $this->automation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('automations/Settings'));
});

it('renames the automation from settings without wiping the graph', function () {
    $automation = Automation::factory()->for($this->workspace)->withScheduleTrigger()->create();
    $originalNodes = $automation->nodes;

    $this->actingAs($this->user)
        ->put(route('app.automations.update', $automation->id), ['name' => 'Renamed flow'])
        ->assertRedirect();

    $automation->refresh();
    expect($automation->name)->toBe('Renamed flow');
    expect($automation->nodes)->toBe($originalNodes);
});

it('renders the invocations tab with a scroll-paginated list', function () {
    AutomationRun::factory()->for($this->automation)->create();

    $this->actingAs($this->user)
        ->get(route('app.automations.invocations', $this->automation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('automations/Invocations')
            ->has('invocations.data', 1)
        );
});

it('renders the metrics tab with aggregated totals over a default 7-day window', function () {
    $this->actingAs($this->user)
        ->get(route('app.automations.metrics', $this->automation->id))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('automations/Metrics')
            ->has('filters.start')
            ->has('filters.end')
            ->has('metrics.totals')
            ->has('metrics.timeseries', 7)
        );
});

it('honours an explicit date range and orders a reversed range', function () {
    $this->actingAs($this->user)
        ->get(route('app.automations.metrics', $this->automation->id).'?start=2026-06-01&end=2026-06-10')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.start', '2026-06-01')
            ->where('filters.end', '2026-06-10')
            ->has('metrics.timeseries', 10)
        );

    // start after end → the controller swaps them rather than erroring.
    $this->actingAs($this->user)
        ->get(route('app.automations.metrics', $this->automation->id).'?start=2026-06-10&end=2026-06-01')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.start', '2026-06-01')
            ->where('filters.end', '2026-06-10')
        );
});
