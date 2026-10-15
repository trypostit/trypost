<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;

/**
 * @return array<string, array{readOnlyHint: mixed, destructiveHint: mixed, openWorldHint: mixed}>
 */
function mcpToolAnnotations(): array
{
    $tools = (new ReflectionClass(TryPostServer::class))->getDefaultProperties()['tools'];

    return collect($tools)
        ->map(fn (string $tool): array => app($tool)->toArray())
        ->mapWithKeys(fn (array $tool): array => [$tool['name'] => (array) $tool['annotations']])
        ->all();
}

/**
 * @return array<string, array{0: bool, 1: bool, 2: bool}> readOnlyHint, destructiveHint, openWorldHint per tool
 */
function mcpExpectedToolAnnotations(): array
{
    return [
        // Posts
        'list-posts-tool' => [true, false, false],
        'get-post-tool' => [true, false, false],
        'create-post-tool' => [false, true, true],
        'create-posts-tool' => [false, true, true],
        'update-post-tool' => [false, true, true],
        'publish-post-tool' => [false, true, true],
        'approve-post-tool' => [false, true, true],
        'reject-post-tool' => [false, true, false],
        'preview-post-tool' => [true, false, false],
        'delete-post-tool' => [false, true, false],
        'attach-media-from-url-tool' => [false, true, true],
        'request-media-upload-tool' => [false, false, false],
        'attach-media-from-upload-tool' => [false, true, false],
        'get-post-metrics-tool' => [true, false, false],
        'list-post-notes-tool' => [true, false, false],
        'create-post-note-tool' => [false, true, false],
        'update-post-note-tool' => [false, true, false],
        'delete-post-note-tool' => [false, true, false],
        'set-post-recurrence-tool' => [false, true, true],
        'clear-post-recurrence-tool' => [false, true, false],

        // Analytics
        'get-analytics-report-tool' => [true, false, false],
        'get-analytics-publication-tool' => [true, false, false],
        'get-channel-insights-tool' => [true, false, false],
        'list-channel-publications-tool' => [true, false, false],

        // Platforms, signatures and labels
        'list-content-types-tool' => [true, false, false],
        'list-signatures-tool' => [true, false, false],
        'create-signature-tool' => [false, false, false],
        'update-signature-tool' => [false, true, false],
        'delete-signature-tool' => [false, true, false],
        'list-labels-tool' => [true, false, false],
        'create-label-tool' => [false, false, false],
        'update-label-tool' => [false, true, false],
        'delete-label-tool' => [false, true, false],

        // Ideas
        'list-ideas-tool' => [true, false, false],
        'get-idea-tool' => [true, false, false],
        'create-idea-tool' => [false, false, false],
        'update-idea-tool' => [false, true, false],
        'delete-ideas-tool' => [false, true, false],
        'duplicate-idea-tool' => [false, false, false],
        'move-ideas-tool' => [false, true, false],
        'list-idea-stages-tool' => [true, false, false],
        'create-idea-stage-tool' => [false, false, false],
        'update-idea-stage-tool' => [false, true, false],
        'delete-idea-stage-tool' => [false, true, false],
        'reorder-idea-stages-tool' => [false, true, false],

        // Channels, queue and network lookups
        'list-social-accounts-tool' => [true, false, false],
        'get-posting-schedule-tool' => [true, false, false],
        'update-posting-schedule-tool' => [false, true, false],
        'generate-posting-schedule-tool' => [false, true, false],
        'copy-posting-schedule-tool' => [false, true, false],
        'list-free-slots-tool' => [true, false, false],
        'reorder-queue-tool' => [false, true, false],
        'move-post-to-slot-tool' => [false, true, false],
        'list-pinterest-boards-tool' => [true, false, false],
        'create-pinterest-board-tool' => [false, false, true],
        'list-discord-channels-tool' => [true, false, false],
        'search-instagram-audio-tool' => [true, false, true],
        'get-instagram-audio-tool' => [true, false, true],
        'get-tiktok-creator-info-tool' => [true, false, false],

        // Repurpose
        'list-repurposes-tool' => [true, false, false],
        'get-repurpose-tool' => [true, false, false],
        'create-repurpose-tool' => [false, false, false],
        'update-repurpose-tool' => [false, true, true],
        'activate-repurpose-tool' => [false, true, true],
        'pause-repurpose-tool' => [false, true, false],
        'resume-repurpose-tool' => [false, true, true],
        'disable-repurpose-tool' => [false, true, false],
        'delete-repurpose-tool' => [false, true, false],
        'list-repurpose-items-tool' => [true, false, false],
        'list-repurpose-source-formats-tool' => [true, false, false],

        // Webhooks
        'list-webhooks-tool' => [true, false, false],
        'get-webhook-tool' => [true, false, false],
        'create-webhook-tool' => [false, false, true],
        'update-webhook-tool' => [false, true, true],
        'delete-webhook-tool' => [false, true, false],
        'send-webhook-test-tool' => [false, false, true],
        'rotate-webhook-secret-tool' => [false, true, false],
        'list-webhook-logs-tool' => [true, false, false],
        'replay-webhook-log-tool' => [false, true, true],

        // Workspace and API keys
        'get-workspace-tool' => [true, false, false],
        'list-api-keys-tool' => [true, false, false],
        'create-api-key-tool' => [false, false, false],
        'delete-api-key-tool' => [false, true, false],
    ];
}

test('every tool declares readOnlyHint, destructiveHint and openWorldHint as explicit booleans', function () {
    foreach (mcpToolAnnotations() as $name => $annotations) {
        foreach (['readOnlyHint', 'destructiveHint', 'openWorldHint'] as $hint) {
            expect($annotations)->toHaveKey($hint, message: "{$name} has no {$hint}");
            expect($annotations[$hint])->toBeBool("{$name} {$hint} is not a boolean");
        }
    }
});

test('a read-only tool is never destructive', function () {
    foreach (mcpToolAnnotations() as $name => $annotations) {
        if ($annotations['readOnlyHint'] ?? false) {
            expect($annotations['destructiveHint'] ?? null)->toBeFalse("{$name} is read-only but destructive");
        }
    }
});

test('list and get tools are read-only, create tools write and delete tools are destructive', function () {
    foreach (mcpToolAnnotations() as $name => $annotations) {
        if (str($name)->startsWith(['list-', 'get-'])) {
            expect($annotations['readOnlyHint'] ?? null)->toBeTrue("{$name} should be read-only");
        }

        if (str($name)->startsWith('create-')) {
            expect($annotations['readOnlyHint'] ?? null)->toBeFalse("{$name} should not be read-only");
        }

        if (str($name)->startsWith('delete-')) {
            expect($annotations['destructiveHint'] ?? null)->toBeTrue("{$name} should be destructive");
        }
    }
});

test('each tool is annotated as expected', function (string $name) {
    [$readOnly, $destructive, $openWorld] = mcpExpectedToolAnnotations()[$name];
    $annotations = mcpToolAnnotations()[$name] ?? [];

    expect($annotations['readOnlyHint'] ?? null)->toBe($readOnly)
        ->and($annotations['destructiveHint'] ?? null)->toBe($destructive)
        ->and($annotations['openWorldHint'] ?? null)->toBe($openWorld);
})->with(fn (): array => array_keys(mcpExpectedToolAnnotations()));

test('the expected annotations list exactly the registered tools', function () {
    expect(array_keys(mcpExpectedToolAnnotations()))->toEqualCanonicalizing(array_keys(mcpToolAnnotations()));
});
