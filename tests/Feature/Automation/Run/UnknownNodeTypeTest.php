<?php

declare(strict_types=1);

use App\Actions\Automation\Run\AdvanceAutomationRun;
use App\Enums\Automation\Run\Status as RunStatus;
use App\Jobs\Automation\ProcessAutomationNode;
use App\Models\Automation;
use App\Models\AutomationRun;

test('a removed webhook node fails the run instead of throwing', function () {
    $automation = Automation::factory()->active()->create([
        'nodes' => [['id' => 'legacy', 'type' => 'webhook', 'position' => ['x' => 0, 'y' => 0], 'data' => []]],
        'connections' => [],
    ]);
    $run = AutomationRun::factory()->for($automation)->create(['status' => RunStatus::Pending]);

    (new ProcessAutomationNode($run, 'legacy'))->handle(app(AdvanceAutomationRun::class));

    $run->refresh();

    expect($run->status)->toBe(RunStatus::Failed);
    expect(data_get($run->error, 'message'))->toBe(__('automations.errors.node_no_longer_exists', [
        'node_id' => 'legacy',
    ]));
    expect($run->finished_at)->not->toBeNull();
});
