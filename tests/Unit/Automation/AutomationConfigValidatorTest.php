<?php

declare(strict_types=1);

use App\Services\Automation\AutomationConfigValidator;

it('reports no issues for nodes that have no dedicated config validator', function () {
    $issues = app(AutomationConfigValidator::class)->issues([
        ['type' => 'trigger', 'data' => ['trigger_type' => 'schedule']],
        ['type' => 'http_request', 'data' => ['url' => 'https://example.com', 'method' => 'POST']],
        ['type' => 'end', 'data' => []],
    ]);

    expect($issues)->toBe([]);
});

it('returns null when every node is valid', function () {
    $message = app(AutomationConfigValidator::class)->firstMessage([
        ['type' => 'http_request', 'data' => ['url' => 'https://example.com', 'method' => 'GET']],
    ]);

    expect($message)->toBeNull();
});
