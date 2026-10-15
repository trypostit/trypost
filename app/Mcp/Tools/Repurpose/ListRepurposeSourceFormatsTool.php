<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Enums\Repurpose\SourceFormat;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Repurpose;
use App\Models\Workspace;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the video formats a repurpose can watch a source account for, such as reels, feed videos and stories. Use these values when creating or updating a repurpose.')]
class ListRepurposeSourceFormatsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'viewAny', Repurpose::class);

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
