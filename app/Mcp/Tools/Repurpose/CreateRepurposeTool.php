<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Repurpose;

use App\Actions\Repurpose\CreateRepurpose;
use App\Enums\Repurpose\PublishMode;
use App\Enums\Repurpose\SourceFormat;
use App\Http\Resources\Api\RepurposeResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Repurpose;
use App\Models\Workspace;
use App\Support\PostPlatformMetaRules;
use App\Support\Requests\Repurpose\RepurposeRequestRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a repurpose. It starts as a draft and only replicates videos published after it is activated. Source must be an Instagram or Facebook account, the only networks that allow downloading the video. Each destination picks the format it publishes as, so a Story can land as a Reel. Google Business cannot be a destination. Required meta (TikTok privacy_level, Pinterest board_id, Discord channel_id) is checked when the repurpose is activated and on every update of an active one. A source account can back one repurpose per source_format and cannot also be a destination.')]
class CreateRepurposeTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'create', Repurpose::class);

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = RepurposeRequestRules::validate($request->all(), $workspace->id);

        try {
            $repurpose = CreateRepurpose::execute($workspace, $request->user(), $validated);
        } catch (ValidationException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured((new RepurposeResource($repurpose))->resolve());
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source_social_account_id' => $schema->string()->required()->description('Instagram or Facebook account to watch.'),
            'source_format' => $schema->string()->enum(array_column(SourceFormat::cases(), 'value'))->description('Which video format to watch: reel, video or story. Defaults to reel.'),
            'publish_mode' => $schema->string()->enum(array_column(PublishMode::cases(), 'value'))->description('publish to schedule each replicated video straight away, or draft to leave it in TryPost for review. Defaults to publish.'),
            'destinations' => $schema->array()
                ->items($schema->object(fn (JsonSchema $destination): array => [
                    'social_account_id' => $destination->string()->required()->description('UUID of a connected account of this workspace (not Google Business, not the source account).'),
                    'content_type' => $destination->string()->required()->description('A content type of that account that accepts video (list-content-types-tool).'),
                    'meta' => $destination->object()->description(PostPlatformMetaRules::documentation().' In a repurpose, thread reply media are given by media id only.'),
                ]))
                ->description('Accounts to republish to. Google Business accounts are rejected.'),
        ];
    }
}
