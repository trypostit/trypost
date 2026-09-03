<?php

declare(strict_types=1);

use App\Actions\Automation\Run\AdvanceAutomationRun;
use App\Jobs\Automation\ProcessAutomationNode;
use App\Models\Automation;
use App\Models\AutomationRun;
use App\Models\Post;
use Illuminate\Support\Facades\Bus;

it('fans out to all targets — continues the run on the first branch and forks a sibling for each other', function () {
    Bus::fake();

    $automation = Automation::factory()->create([
        'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
            ['id' => 'a', 'type' => 'end', 'position' => ['x' => 1, 'y' => 0], 'data' => []],
            ['id' => 'b', 'type' => 'end', 'position' => ['x' => 1, 'y' => 1], 'data' => []],
            ['id' => 'c', 'type' => 'end', 'position' => ['x' => 1, 'y' => 2], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 't', 'target' => 'a'],
            ['id' => 'e2', 'source' => 't', 'target' => 'b'],
            ['id' => 'e3', 'source' => 't', 'target' => 'c'],
        ],
    ]);

    $run = AutomationRun::factory()->for($automation)->create(['context' => ['shared' => 'value']]);

    app(AdvanceAutomationRun::class)($run, 't');

    // One job per branch (the original run + two forked siblings).
    Bus::assertDispatchedTimes(ProcessAutomationNode::class, 3);

    // Two sibling runs created (original + 2), each sharing the parent context.
    $runs = AutomationRun::where('automation_id', $automation->id)->get();
    expect($runs)->toHaveCount(3);
    $runs->each(fn ($r) => expect($r->context)->toBe(['shared' => 'value']));
});

it('forks siblings that inherit the generated post, so a branch after Generate can publish', function () {
    Bus::fake();

    $post = Post::factory()->create();

    $automation = Automation::factory()->create([
        'nodes' => [
            ['id' => 'g', 'type' => 'generate', 'position' => ['x' => 0, 'y' => 0], 'data' => []],
            ['id' => 'p', 'type' => 'publish', 'position' => ['x' => 1, 'y' => 0], 'data' => ['mode' => 'now']],
            ['id' => 'w', 'type' => 'end', 'position' => ['x' => 1, 'y' => 1], 'data' => []],
        ],
        'connections' => [
            ['id' => 'e1', 'source' => 'g', 'target' => 'p'],
            ['id' => 'e2', 'source' => 'g', 'target' => 'w'],
        ],
    ]);

    $run = AutomationRun::factory()->for($automation)->create([
        'current_node_id' => 'g',
        'generated_post_id' => $post->id,
    ]);

    app(AdvanceAutomationRun::class)($run, 'g');

    $sibling = AutomationRun::where('automation_id', $automation->id)
        ->whereKeyNot($run->id)
        ->sole();

    expect($sibling->generated_post_id)->toBe($post->id);
});

it('completes the run when a handle has no connected target', function () {
    Bus::fake();

    $automation = Automation::factory()->create([
        'nodes' => [
            ['id' => 't', 'type' => 'trigger', 'position' => ['x' => 0, 'y' => 0], 'data' => ['trigger_type' => 'schedule']],
        ],
        'connections' => [],
    ]);

    $run = AutomationRun::factory()->for($automation)->create();

    app(AdvanceAutomationRun::class)($run, 't');

    expect($run->fresh()->status->value)->toBe('completed');
    Bus::assertNotDispatched(ProcessAutomationNode::class);
});
