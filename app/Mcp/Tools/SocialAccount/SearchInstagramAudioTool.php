<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\SearchInstagramAudio;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Support\Social\InstagramAudio;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\Rule;
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
#[IsOpenWorld(true)]
#[Description('Search Instagram audio (licensed music and original sounds) to attach to a reel, for an Instagram account connected through Facebook. Omit q for what is trending. Use a returned id as meta.audio.id (with optional title, artist, audio_volume and video_volume from 0 to 100) on an instagram_reel post. Results are cached for ten minutes; a workspace may make '.InstagramAudio::LOOKUPS_PER_MINUTE.' uncached lookups per minute.')]
class SearchInstagramAudioTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'account_id' => ['required', 'string', 'uuid'],
            'q' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', 'string', Rule::in(InstagramAudio::TYPES)],
        ]);

        $account = SocialAccount::where('workspace_id', $workspace->id)->find(data_get($validated, 'account_id'));

        if (! $account) {
            return Response::error('Social account not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'view', $account, 'Social account not found.')) {
            return $denied;
        }

        if (! in_array($account->platform, [Platform::Instagram, Platform::InstagramFacebook], true)) {
            return Response::error('This tool only works with Instagram social accounts.');
        }

        if ($account->platform !== Platform::InstagramFacebook) {
            return Response::error(__('posts.form.instagram.music.requires_facebook'));
        }

        try {
            return Response::structured([
                'audio' => SearchInstagramAudio::execute($account, data_get($validated, 'q'), data_get($validated, 'type')),
            ]);
        } catch (ThrottleRequestsException $e) {
            return Response::error($e->getMessage());
        } catch (ConnectionException|RequestException) {
            return Response::error(__('posts.form.instagram.music.search_failed'));
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of an Instagram account connected through Facebook.'),
            'q' => $schema->string()->description('Search words. Omit for trending audio.'),
            'type' => $schema->string()->enum(InstagramAudio::TYPES)->description('music or original_sound. Omit for both, music first.'),
        ];
    }
}
