<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\UpdateRepurpose;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\ResolvesWorkspaceRepurpose;
use App\Mcp\Requests\Repurpose\RepurposeIdRequest;
use App\Models\Repurpose;
use App\Models\Workspace;
use App\Support\PostPlatformMetaRules;
use App\Support\Requests\Repurpose\RepurposeRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update a repurpose. Changing the source account or the watched format resets the watermark, so only videos published after the change are replicated. Required meta (TikTok privacy_level, Pinterest board_id, Discord channel_id) is checked when the repurpose is activated and on every update of an active one. A source account can back one repurpose per source_format and cannot also be a destination.')]
class UpdateRepurposeTool extends Tool
{
    use AuthorizesMcpTool, ResolvesWorkspaceRepurpose;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->currentWorkspace($request);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $identified = $request->validate(RepurposeIdRequest::rules());
        $repurpose = $this->repurposeInWorkspace($workspace, $identified['repurpose_id']);

        if (! $repurpose instanceof Repurpose) {
            return $repurpose;
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $repurpose, 'Repurpose not found.')) {
            return $denied;
        }

        $validated = RepurposeRequestRules::validate($request->all(), $workspace->id, $repurpose);

        return Response::structured(
            (new RepurposeResource(UpdateRepurpose::execute($repurpose, $validated)))->resolve(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'repurpose_id' => $schema->string()->required()->description('The repurpose to update.'),
            'source_social_account_id' => $schema->string()->description('Move the repurpose to another source account.'),
            'source_format' => $schema->string()->enum(array_column(SourceFormat::cases(), 'value'))->description('Which video format to watch: reel, video or story.'),
            'publish_mode' => $schema->string()->enum(array_column(PublishMode::cases(), 'value'))->description('publish to schedule each replicated video straight away, or draft to leave it in TryPost for review.'),
            'destinations' => $schema->array()
                ->items($schema->object(fn (JsonSchema $destination): array => [
                    'social_account_id' => $destination->string()->required()->description('UUID of a connected account of this workspace (not Google Business, not the source account).'),
                    'content_type' => $destination->string()->required()->description('A content type of that account that accepts video (list-content-types-tool).'),
                    'meta' => $destination->object()->description(PostPlatformMetaRules::documentation()),
                ]))
                ->description('Replaces the destination list.'),
        ];
    }
}
