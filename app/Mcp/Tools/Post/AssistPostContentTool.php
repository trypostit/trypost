<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Ai\AssistPostContent;
use App\Enums\Ai\PostAssistantMode;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Support\AiPromptRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Suggest a social post caption or revise an existing caption with AI. Returns text for review; it does not create or update a post.')]
class AssistPostContentTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost', 'Not authorized to create posts.');
        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        if ($this->denyUnlessCan($request, 'useAi', $workspace->account, 'AI is unavailable for this account.')) {
            return Response::error('AI is unavailable for this account.');
        }

        $validated = $request->validate([
            'mode' => ['required', Rule::enum(PostAssistantMode::class)],
            'current_content' => [
                Rule::requiredIf(fn (): bool => PostAssistantMode::tryFrom((string) $request->get('mode'))?->requiresContent() ?? false),
                'nullable', 'string', 'max:10000',
            ],
            'prompt' => [
                Rule::requiredIf(fn (): bool => $request->get('mode') === PostAssistantMode::WriteMore->value),
                'nullable', 'string', 'max:'.AiPromptRules::PROMPT_MAX_LENGTH,
            ],
        ]);

        return Response::structured([
            'content' => AssistPostContent::execute(
                workspace: $workspace,
                user: $request->user(),
                mode: PostAssistantMode::from($validated['mode']),
                currentContent: $validated['current_content'] ?? '',
                prompt: $validated['prompt'] ?? null,
            ),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'mode' => $schema->string()->enum(array_column(PostAssistantMode::cases(), 'value'))->required(),
            'current_content' => $schema->string()->description('Existing caption. Required for rephrase, shorten, or expand.'),
            'prompt' => $schema->string()->description('What to write about. Required for write_more.'),
        ];
    }
}
