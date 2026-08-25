<?php

declare(strict_types=1);

use App\Actions\Automation\Automation\DeleteAutomation;
use App\Actions\Automation\Automation\GetAutomationEditorData;
use App\Actions\Automation\Automation\GetAutomationInvocations;
use App\Actions\Automation\Automation\GetAutomationMetrics;
use App\Actions\Automation\Automation\ListAutomations;
use App\Enums\Automation\Run\Status as RunStatus;
use App\Enums\SocialAccount\Platform;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Post;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\PinterestPublisher;
use App\Services\Social\TikTokCreatorInfo;

it('lists only the workspace automations, newest first', function () {
    $workspace = Workspace::factory()->create();
    $other = Workspace::factory()->create();

    $older = Automation::factory()->for($workspace)->create(['created_at' => now()->subDay()]);
    $newer = Automation::factory()->for($workspace)->create(['created_at' => now()]);
    Automation::factory()->for($other)->create();

    $result = app(ListAutomations::class)($workspace);

    expect($result->total())->toBe(2);
    expect($result->items()[0]->id)->toBe($newer->id);
    expect($result->items()[1]->id)->toBe($older->id);
});

it('deletes the automation', function () {
    $automation = Automation::factory()->create();

    app(DeleteAutomation::class)($automation);

    expect(Automation::find($automation->id))->toBeNull();
});

it('shows only production runs — never dry runs or manual test runs', function () {
    $automation = Automation::factory()->create();
    $real = AutomationRun::factory()->for($automation)->create();
    AutomationRun::factory()->for($automation)->create(['is_dry_run' => true]);
    // A "test with real data" run: not a dry run, but still manual — must be hidden.
    AutomationRun::factory()->for($automation)->create(['is_manual' => true, 'is_dry_run' => false]);

    $result = app(GetAutomationInvocations::class)($automation);

    expect($result->total())->toBe(1);
    expect($result->items()[0]->id)->toBe($real->id);
    expect($result->items()[0]->node_runs_count)->toBe(0);
});

it('filters invocations by status', function () {
    $automation = Automation::factory()->create();
    $failed = AutomationRun::factory()->for($automation)->create(['status' => RunStatus::Failed->value]);
    AutomationRun::factory()->for($automation)->create(['status' => RunStatus::Completed->value]);

    $result = app(GetAutomationInvocations::class)($automation, RunStatus::Failed->value);

    expect($result->total())->toBe(1);
    expect($result->items()[0]->id)->toBe($failed->id);
});

it('searches invocations by run id', function () {
    $automation = Automation::factory()->create();
    $match = AutomationRun::factory()->for($automation)->create();
    AutomationRun::factory()->for($automation)->create();

    // UUIDv7 ids share a timestamp prefix, so match on the random suffix to
    // assert the LIKE actually narrows the result set.
    $result = app(GetAutomationInvocations::class)($automation, null, substr($match->id, -8));

    expect($result->total())->toBe(1);
    expect($result->items()[0]->id)->toBe($match->id);
});

it('aggregates run metrics over the period, excluding dry runs', function () {
    $automation = Automation::factory()->create();
    AutomationRun::factory()->for($automation)->create([
        'status' => RunStatus::Completed->value,
        'started_at' => now()->subSeconds(2),
        'finished_at' => now(),
    ]);
    AutomationRun::factory()->for($automation)->create(['status' => RunStatus::Failed->value]);
    AutomationRun::factory()->for($automation)->create(['status' => RunStatus::Completed->value, 'is_dry_run' => true]);
    // A manual "test with real data" run must not inflate the metrics either.
    AutomationRun::factory()->for($automation)->create(['status' => RunStatus::Completed->value, 'is_manual' => true]);

    $metrics = app(GetAutomationMetrics::class)($automation, now()->subDays(6), now());

    expect($metrics['totals']['runs'])->toBe(2);
    expect($metrics['totals']['completed'])->toBe(1);
    expect($metrics['totals']['failed'])->toBe(1);
    expect($metrics['totals']['success_rate'])->toBe(50);
    expect($metrics['timeseries'])->toHaveCount(7);
    expect($metrics['timeseries'][6]['started'])->toBe(2);
    expect($metrics['timeseries'][6]['completed'])->toBe(1);
    expect($metrics['timeseries'][6]['failed'])->toBe(1);
});

it('breaks down generated posts by platform', function () {
    $automation = Automation::factory()->create();
    $post = Post::factory()->create();
    PostPlatform::factory()->for($post)->create(['platform' => Platform::LinkedIn->value]);
    PostPlatform::factory()->for($post)->create(['platform' => Platform::X->value]);
    AutomationRun::factory()->for($automation)->create(['generated_post_id' => $post->id]);

    $metrics = app(GetAutomationMetrics::class)($automation, now()->subDays(6), now());

    expect($metrics['platforms'])->toHaveCount(2);
    expect(collect($metrics['platforms'])->pluck('platform')->all())
        ->toContain(Platform::LinkedIn->value, Platform::X->value);
});

it('returns only active social accounts for the automation workspace', function () {
    config()->set('trypost.allow_multiple_social_accounts', true);
    $this->mock(PinterestPublisher::class);
    $this->mock(TikTokCreatorInfo::class);

    $workspace = Workspace::factory()->create();
    $automation = Automation::factory()->for($workspace)->create();
    $active = SocialAccount::factory()->for($workspace)->create(['platform' => 'instagram', 'is_active' => true]);
    SocialAccount::factory()->for($workspace)->create(['platform' => 'instagram', 'is_active' => false]);

    $result = app(GetAutomationEditorData::class)($automation);

    expect($result['socialAccounts'])->toHaveCount(1);
    expect($result['socialAccounts']->first()->id)->toBe($active->id);
    expect($result['pinterestBoards'])->toBeEmpty();
    expect($result['tiktokCreatorInfos'])->toBeEmpty();
});

it('maps pinterest boards for pinterest accounts', function () {
    $this->mock(PinterestPublisher::class, fn ($mock) => $mock->shouldReceive('getBoards')->andReturn([
        'boards' => [['id' => 'b1', 'name' => 'Ideas']],
        'truncated' => true,
    ]));
    $this->mock(TikTokCreatorInfo::class);

    $workspace = Workspace::factory()->create();
    $automation = Automation::factory()->for($workspace)->create();
    $pinterest = SocialAccount::factory()->for($workspace)->create(['platform' => Platform::Pinterest->value, 'is_active' => true]);

    $result = app(GetAutomationEditorData::class)($automation);

    expect($result['pinterestBoards']->get($pinterest->id))->toBe([
        'boards' => [['id' => 'b1', 'name' => 'Ideas']],
        'truncated' => true,
    ]);
});
