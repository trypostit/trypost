<?php

declare(strict_types=1);

use App\Actions\Ai\RecordAiUsage;
use App\Enums\Ai\UsageType;
use App\Models\AiUsageLog;
use App\Models\Workspace;

test('AI usage records text image and template activity for the workspace', function () {
    $workspace = Workspace::factory()->create();

    RecordAiUsage::recordText($workspace, 12, 8, 'openai', 'test-model', metadata: ['source' => 'editor']);
    RecordAiUsage::recordImage($workspace, 'openai', 'gpt-image-1');
    RecordAiUsage::recordTemplate($workspace, 'template');

    $usages = AiUsageLog::query()->where('workspace_id', $workspace->id)->get();
    $text = $usages->firstWhere('type', UsageType::Text);
    $image = $usages->firstWhere('type', UsageType::Image);
    $template = $usages->firstWhere('type', UsageType::Template);

    expect($usages)->toHaveCount(3)
        ->and($text->account_id)->toBe($workspace->account_id)
        ->and($text->total_tokens)->toBe(20)
        ->and($text->metadata)->toEqual(['source' => 'editor'])
        ->and($image->provider)->toBe('openai')
        ->and($template->credits)->toBe(0);
});
