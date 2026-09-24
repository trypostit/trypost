<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Ai\Agents\PostWritingAssistant;
use App\Enums\Ai\PostAssistantMode;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Ai\RecordAiUsage;

final class AssistPostContent
{
    public static function execute(
        Workspace $workspace,
        User $user,
        PostAssistantMode $mode,
        string $currentContent,
        ?string $prompt,
    ): string {
        $agent = new PostWritingAssistant($workspace, $mode, $currentContent);
        $response = $agent->prompt(trim($prompt ?? '') ?: 'Revise the existing caption as instructed.');

        RecordAiUsage::recordText(
            workspace: $workspace,
            promptTokens: $response->usage->promptTokens,
            completionTokens: $response->usage->completionTokens,
            provider: (string) $response->meta->provider,
            model: (string) $response->meta->model,
            userId: $user->id,
            metadata: ['agent' => 'post_writing_assistant', 'mode' => $mode->value],
        );

        return trim((string) $response);
    }
}
