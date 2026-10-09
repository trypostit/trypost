<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Enums\SocialAccount\Platform;
use App\Exceptions\PlatformUnavailableException;
use App\Http\Resources\Api\TikTokCreatorInfoResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Services\Social\TikTokCreatorInfo;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Name('get-tiktok-creator-info-tool')]
#[Description('Read what TikTok lets a connected TikTok account publish right now: privacy_level_options (the only privacy_level values allowed for this creator), comment_disabled / duet_disabled / stitch_disabled (when true, allow_comments / allow_duet / allow_stitch must stay false), max_video_post_duration_sec (the longest video TikTok accepts from this creator; it is not checked when you schedule, so compare the video duration yourself), and can_post. Call it before creating or scheduling a TikTok post and pick meta.privacy_level (destinations[].meta.privacy_level in create-posts-tool) from privacy_level_options. can_post false means TikTok banned this creator from posting. When TikTok reports a posting limit (daily cap, quota or rate limit) the tool returns an error instead: scheduled posts retry automatically once it lifts. If TikTok does not answer, the tool returns an error: retry in a moment.')]
class GetTikTokCreatorInfoTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request, TikTokCreatorInfo $tikTokCreatorInfo): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(['account_id' => ['required', 'string', 'uuid']]);

        $account = SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->find(data_get($validated, 'account_id'));

        if (! $account instanceof SocialAccount) {
            return Response::error('Social account not found.');
        }

        if ($account->platform !== Platform::TikTok) {
            return Response::error('This tool only works with TikTok social accounts.');
        }

        try {
            $creatorInfo = $tikTokCreatorInfo->interactive()->fetchOrFail($account);
        } catch (PlatformUnavailableException $e) {
            return Response::error($e->getMessage());
        }

        return Response::structured((new TikTokCreatorInfoResource($creatorInfo))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected TikTok social account.'),
        ];
    }
}
