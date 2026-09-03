<?php

declare(strict_types=1);

namespace App\Services\Automation;

use App\Enums\Automation\Node\Type as NodeType;

/**
 * Single source of truth for per-node config validation. Walks an automation's
 * nodes and reports every config issue, delegating to the type-specific
 * validators. Shared by save (field-keyed errors), activation, and the editor
 * test run so a misconfigured node is rejected up front with a clear message
 * instead of failing midway through execution.
 */
final class AutomationConfigValidator
{
    public function __construct(
        private GenerateNodeValidator $generateValidator,
    ) {}

    /**
     * Every config issue across the given nodes, in node order.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     * @return list<array{node_index: int, field: string, message: string}>
     */
    public function issues(array $nodes): array
    {
        $issues = [];

        foreach ($nodes as $index => $node) {
            $config = (array) data_get($node, 'data', []);

            [$field, $message] = match (data_get($node, 'type')) {
                NodeType::Generate->value => ['accounts', $this->generateValidator->issueFor($config)],
                default => [null, null],
            };

            if ($message !== null) {
                $issues[] = ['node_index' => $index, 'field' => $field, 'message' => $message];
            }
        }

        return $issues;
    }

    /**
     * The first config issue's message, or null when every node is runnable.
     *
     * @param  array<int, array<string, mixed>>  $nodes
     */
    public function firstMessage(array $nodes): ?string
    {
        return $this->issues($nodes)[0]['message'] ?? null;
    }
}
