<?php

declare(strict_types=1);

use App\Actions\Analytics\GetAnalyticsBounds;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;

test('workspace analytics remains available when a provider is unavailable', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);
    Http::fake(['*' => Http::response([], 503)]);

    $this->actingAs($user)
        ->get(route('app.analytics'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('analytics/Index')
            ->where('report.summary.followers.value', null)
            ->etc());

    Http::assertNothingSent();
});

test('a read-model defect is not hidden behind empty numbers', function () {
    $user = User::factory()->create();
    $workspace = Workspace::factory()->create(['user_id' => $user->id]);
    $user->update(['current_workspace_id' => $workspace->id]);

    $this->mock(GetAnalyticsBounds::class)
        ->shouldReceive('execute')
        ->andThrow(new RuntimeException('a real report bug'));

    $this->actingAs($user)
        ->get(route('app.analytics'))
        ->assertStatus(500);
});
