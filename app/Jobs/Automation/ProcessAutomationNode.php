<?php

declare(strict_types=1);

namespace App\Jobs\Automation;

use App\Actions\Automation\Node\RunConditionNode;
use App\Actions\Automation\Node\RunDelayNode;
use App\Actions\Automation\Node\RunEndNode;
use App\Actions\Automation\Node\RunFetchRssNode;
use App\Actions\Automation\Node\RunGenerateNode;
use App\Actions\Automation\Node\RunHttpRequestNode;
use App\Actions\Automation\Node\RunPublishNode;
use App\Actions\Automation\Run\AdvanceAutomationRun;
use App\DataTransferObjects\Automation\NodeRunResult;
use App\Enums\Automation\Node\Type as NodeType;
use App\Enums\Automation\NodeRun\Status as NodeRunStatus;
use App\Enums\Automation\Run\Status as RunStatus;
use App\Enums\Automation\Status as AutomationStatus;
use App\Models\AutomationNodeRun;
use App\Models\AutomationRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use LogicException;
use Throwable;

class ProcessAutomationNode implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public AutomationRun $run,
        public string $nodeId,
    ) {
        $this->onQueue('automations');
    }

    public function handle(AdvanceAutomationRun $advance): void
    {
        $this->run->refresh();

        if (! in_array($this->run->status, [RunStatus::Pending, RunStatus::Running, RunStatus::Waiting], true)) {
            return;
        }

        if (! $this->run->is_manual && $this->run->automation->status !== AutomationStatus::Active) {
            return;
        }

        $node = collect($this->run->automation->nodes ?? [])->firstWhere('id', $this->nodeId);

        if ($node === null) {
            $this->run->update([
                'status' => RunStatus::Failed,
                'error' => ['message' => __('automations.errors.node_no_longer_exists', ['node_id' => $this->nodeId])],
                'finished_at' => now(),
            ]);

            return;
        }

        $nodeType = NodeType::tryFrom((string) data_get($node, 'type', ''));

        if ($nodeType === null) {
            $this->run->update([
                'status' => RunStatus::Failed,
                'error' => ['message' => __('automations.errors.node_no_longer_exists', ['node_id' => $this->nodeId])],
                'finished_at' => now(),
            ]);

            return;
        }

        $this->run->update([
            'status' => RunStatus::Running,
            'current_node_id' => $this->nodeId,
            'started_at' => $this->run->started_at ?? now(),
        ]);

        $nodeRun = AutomationNodeRun::create([
            'run_id' => $this->run->id,
            'node_id' => $this->nodeId,
            'node_type' => $nodeType,
            'status' => NodeRunStatus::Running,
            'input' => $this->run->context,
            'started_at' => now(),
        ]);

        try {
            $result = $this->executeNode($nodeType, $node['data'] ?? []);
        } catch (Throwable $e) {
            $result = NodeRunResult::failed($e->getMessage(), ['class' => $e::class]);
        }

        $nodeRun->update([
            'status' => $result->status,
            'output' => $result->output,
            'error' => $result->error,
            'finished_at' => now(),
        ]);

        if ($result->status === NodeRunStatus::Failed) {
            $this->run->update([
                'status' => RunStatus::Failed,
                'error' => array_merge(['node_id' => $this->nodeId], $result->error ?? []),
                'finished_at' => now(),
            ]);

            return;
        }

        $this->run->update([
            'context' => array_merge($this->run->context ?? [], $result->output),
        ]);

        if ($result->sleepUntil !== null) {
            $this->run->update([
                'status' => RunStatus::Waiting,
                'next_action_at' => $result->sleepUntil,
            ]);

            return;
        }

        $advance($this->run, $this->nodeId, $result->nextHandle);
    }

    public function failed(?Throwable $e): void
    {
        $this->run->refresh();

        if (in_array($this->run->status, [RunStatus::Completed, RunStatus::Failed], true)) {
            return;
        }

        $this->run->update([
            'status' => RunStatus::Failed,
            'error' => ['message' => $e?->getMessage() ?? 'job failed', 'node_id' => $this->nodeId],
            'finished_at' => now(),
        ]);
    }

    private function executeNode(NodeType $type, array $config): NodeRunResult
    {
        $handler = match ($type) {
            NodeType::Generate => app(RunGenerateNode::class),
            NodeType::Delay => app(RunDelayNode::class),
            NodeType::Condition => app(RunConditionNode::class),
            NodeType::Publish => app(RunPublishNode::class),
            NodeType::End => app(RunEndNode::class),
            NodeType::FetchRss => app(RunFetchRssNode::class),
            NodeType::HttpRequest => app(RunHttpRequestNode::class),
            NodeType::Trigger => throw new LogicException('Trigger nodes are not executed as run steps.'),
        };

        return $handler($this->run, $config);
    }
}
