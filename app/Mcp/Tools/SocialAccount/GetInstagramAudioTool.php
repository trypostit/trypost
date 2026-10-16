<?php

declare(strict_types=1);

namespace App\Mcp\Tools\SocialAccount;

use App\Actions\SocialAccount\GetInstagramAudio;
use App\Enums\SocialAccount\Platform;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
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
#[Description('Get one Instagram audio asset by id (the meta.audio.id of a reel, or an id from search-instagram-audio-tool): title, artist, duration and fresh cover and preview links, which Instagram expires. Fails when the track is no longer available. Requires an Instagram account connected through Facebook (platform instagram-facebook).')]
class GetInstagramAudioTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'createPost');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate(['account_id' => ['required', 'string', 'uuid']]);

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

        $audioId = (string) data_get($request->validate([
            'audio_id' => ['required', 'string', 'regex:/^[0-9]+$/'],
        ]), 'audio_id');

        try {
            return Response::structured(GetInstagramAudio::execute($account, $audioId));
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
            'audio_id' => $schema->string()->required()->description('The Instagram audio asset id.'),
        ];
    }
}
