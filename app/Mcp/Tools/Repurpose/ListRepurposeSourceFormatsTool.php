<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description('List the video formats a repurpose can watch a source account for, such as reels, feed videos and stories. Use these values when creating or updating a repurpose.')]
#[IsReadOnly]
class ListRepurposeSourceFormatsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'manageRepurposes', 'Not authorized to manage repurposes.');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        return Response::structured([
            'source_formats' => array_map(
                fn (SourceFormat $format): array => ['value' => $format->value, 'label' => $format->label()],
                SourceFormat::cases(),
            ),
        ]);
    }
}
