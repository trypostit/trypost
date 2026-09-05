<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Support\Repurpose\Templates;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('Ready-made repurpose starting points and the video formats a source can be watched for. Use this before create-repurpose-tool to suggest a sensible source and destination combination.')]
#[IsReadOnly]
class ListRepurposeTemplatesTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        return Response::structured([
            'templates' => Templates::all(),
            'source_formats' => array_map(
                fn (SourceFormat $format): array => ['value' => $format->value, 'label' => $format->label()],
                SourceFormat::cases(),
            ),
        ]);
    }
}
