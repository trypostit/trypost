<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Enums\Ai\UsageType;
use App\Models\AiUsageLog;
use App\Models\Workspace;
use App\Services\Ai\CreditCost;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RecordAiUsage
{
    /** @param array<string, mixed> $metadata */
    public static function recordText(
        Workspace $workspace,
        int $promptTokens,
        int $completionTokens,
        ?string $provider = null,
        ?string $model = null,
        ?string $userId = null,
        ?string $postId = null,
        array $metadata = [],
    ): void {
        $totalTokens = $promptTokens + $completionTokens;

        self::persist(
            workspace: $workspace,
            type: UsageType::Text,
            credits: CreditCost::forText($totalTokens),
            provider: $provider,
            model: $model,
            promptTokens: $promptTokens,
            completionTokens: $completionTokens,
            totalTokens: $totalTokens,
            userId: $userId,
            postId: $postId,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public static function recordImage(
        Workspace $workspace,
        string $provider,
        string $model,
        ?string $userId = null,
        ?string $postId = null,
        array $metadata = [],
    ): void {
        self::persist(
            workspace: $workspace,
            type: UsageType::Image,
            credits: CreditCost::forImage($model),
            provider: $provider,
            model: $model,
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            userId: $userId,
            postId: $postId,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    public static function recordTemplate(
        Workspace $workspace,
        ?string $provider = null,
        ?string $userId = null,
        ?string $postId = null,
        array $metadata = [],
    ): void {
        self::persist(
            workspace: $workspace,
            type: UsageType::Template,
            credits: 0,
            provider: $provider,
            model: null,
            promptTokens: 0,
            completionTokens: 0,
            totalTokens: 0,
            userId: $userId,
            postId: $postId,
            metadata: $metadata,
        );
    }

    /** @param array<string, mixed> $metadata */
    private static function persist(
        Workspace $workspace,
        UsageType $type,
        int $credits,
        ?string $provider,
        ?string $model,
        int $promptTokens,
        int $completionTokens,
        int $totalTokens,
        ?string $userId,
        ?string $postId,
        array $metadata,
    ): void {
        try {
            AiUsageLog::create([
                'account_id' => $workspace->account_id,
                'workspace_id' => $workspace->id,
                'user_id' => $userId,
                'post_id' => $postId,
                'type' => $type,
                'provider' => $provider,
                'model' => $model,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $totalTokens,
                'credits' => $credits,
                'metadata' => $metadata !== [] ? $metadata : null,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to record AI usage', [
                'workspace_id' => $workspace->id,
                'type' => $type->value,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
