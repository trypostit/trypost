<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Media\ConnectCanva;
use App\Enums\Media\CanvaPreset;
use App\Enums\Media\Source;
use App\Exceptions\Media\CanvaReconnectRequired;
use App\Http\Requests\App\Integration\CreateCanvaDesignRequest;
use App\Http\Requests\App\Integration\EditCanvaDesignRequest;
use App\Http\Resources\App\CanvaReturnResource;
use App\Jobs\Media\ExportCanvaDesign;
use App\Models\Media;
use App\Models\MediaSourceConnection;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\Sources\CanvaClient;
use App\Support\MediaImportStatus;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * The Canva popup: sign in (PKCE) when needed, open a new design at the
 * preset size or reopen the design a media item came from, and turn
 * "Return to TryPost" into a queued PNG export.
 *
 * The composer names each popup with a nonce. Canva's pages cut the popup
 * off from its opener, so the result is delivered under that nonce: the
 * popup page broadcasts it, and `showReturn` answers it as a fallback.
 */
class CanvaController extends Controller implements HasMiddleware
{
    private const string OAUTH_SESSION_KEY = 'canva_oauth';

    private const int CORRELATION_TTL_SECONDS = 86400;

    private const int JTI_TTL_SECONDS = 86400;

    private const int RETURN_TTL_SECONDS = 3600;

    private const string RETURN_JWT_TYPE = 'rti';

    public function __construct(private readonly CanvaClient $canva, private readonly RemoteMediaImporter $importer) {}

    /**
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next): mixed {
                abort_unless(Source::Canva->isEnabled(), HttpResponse::HTTP_NOT_FOUND);

                return $next($request);
            },
        ];
    }

    public function createDesign(CreateCanvaDesignRequest $request): RedirectResponse|Response
    {
        $user = $request->user();
        $preset = $request->preset();
        $nonce = $request->nonce();
        $connection = $user->mediaSourceConnections()->where('source', Source::Canva)->first();

        if ($connection === null) {
            return $this->startOAuth($request, $nonce, preset: $preset);
        }

        try {
            return $this->openDesign($user, $user->currentWorkspace, $connection, $preset, $nonce);
        } catch (CanvaReconnectRequired) {
            return $this->startOAuth($request, $nonce, preset: $preset);
        } catch (Throwable) {
            return $this->popup(false, $nonce, 'posts.composer.media_sources.errors.canva_connect_failed');
        }
    }

    /**
     * Reopens the design a Canva-made media item came from. The `edit_url`
     * is fetched fresh with this user's connection (it is per Canva user and
     * expires), and the return replaces that item.
     */
    public function editDesign(EditCanvaDesignRequest $request): RedirectResponse|Response
    {
        $user = $request->user();
        $nonce = $request->nonce();
        $media = $request->media();
        $designId = $request->designId();
        $connection = $user->mediaSourceConnections()->where('source', Source::Canva)->first();

        if ($connection === null) {
            return $this->startOAuth($request, $nonce, media: $media, designId: $designId);
        }

        try {
            return $this->openExistingDesign($user, $user->currentWorkspace, $connection, $designId, $media->id, $nonce);
        } catch (CanvaReconnectRequired) {
            return $this->startOAuth($request, $nonce, media: $media, designId: $designId);
        } catch (Throwable) {
            return $this->popup(false, $nonce, 'posts.composer.media_sources.errors.canva_edit_failed');
        }
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $pending = $request->session()->pull(self::OAUTH_SESSION_KEY);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $preset = CanvaPreset::tryFrom((string) data_get($pending, 'preset'));
        $mediaId = data_get($pending, 'media_id');
        $designId = data_get($pending, 'design_id');
        $editing = is_string($mediaId) && is_string($designId);
        $nonce = data_get($pending, 'nonce');

        if (! is_array($pending)
            || $state === ''
            || $code === ''
            || ($preset === null && ! $editing)
            || ! is_string($nonce)
            || ! hash_equals((string) data_get($pending, 'state'), $state)
            || data_get($pending, 'workspace_id') !== $workspace->id) {
            return $this->popup(false, is_string($nonce) ? $nonce : null, 'posts.composer.media_sources.errors.canva_connect_failed');
        }

        try {
            $tokens = $this->canva->exchangeCode($code, (string) data_get($pending, 'verifier'));
            $connection = ConnectCanva::execute($user, $tokens, $this->canva->currentUser($tokens->accessToken));

            if (! $editing) {
                return $this->openDesign($user, $workspace, $connection, $preset, $nonce);
            }
        } catch (Throwable) {
            return $this->popup(false, $nonce, 'posts.composer.media_sources.errors.canva_connect_failed');
        }

        try {
            return $this->openExistingDesign($user, $workspace, $connection, $designId, $mediaId, $nonce);
        } catch (Throwable) {
            return $this->popup(false, $nonce, 'posts.composer.media_sources.errors.canva_edit_failed');
        }
    }

    public function return(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $this->authorize('createPost', $workspace);

        try {
            $claims = $this->canva->verifyReturnJwt((string) $request->query('correlation_jwt', ''));
        } catch (Throwable $exception) {
            Log::warning('Canva return refused', ['reason' => 'invalid_jwt', 'error' => $exception->getMessage(), 'user_id' => $user->id]);

            return $this->popup(false, null, 'posts.composer.media_sources.errors.canva_export_failed');
        }

        $correlation = $this->ownCorrelation($claims, $user, $workspace);
        $nonce = data_get($correlation, 'nonce');
        $designId = (string) data_get($claims, 'design_id', '');

        $replaces = data_get($correlation, 'replaces_media_id');

        $refusal = match (true) {
            $correlation === null => 'unknown_correlation',
            $designId === '' => 'missing_design',
            ! $this->returnedBy($claims, $user, (string) data_get($correlation, 'connection_id')) => 'other_canva_user',
            $replaces !== null && ! Media::query()->where('workspace_id', $workspace->id)->whereKey($replaces)->exists() => 'replaced_media_gone',
            ! Cache::add('canva-return-jti:'.data_get($claims, 'jti'), true, self::JTI_TTL_SECONDS) => 'replayed',
            default => null,
        };

        if ($refusal !== null) {
            Log::warning('Canva return refused', ['reason' => $refusal, 'user_id' => $user->id]);

            return $this->popup(false, $nonce, 'posts.composer.media_sources.errors.canva_export_failed');
        }

        $importId = (string) Str::uuid();

        MediaImportStatus::pending($importId, $user->id, $workspace->id, $replaces);

        ExportCanvaDesign::dispatch($importId, (string) data_get($correlation, 'connection_id'), $designId, $workspace->id, $user->id);

        Cache::put($this->returnKey($user, $nonce), [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'import_id' => $importId,
            'replaces' => $replaces,
        ], self::RETURN_TTL_SECONDS);

        return $this->popup(true, $nonce, importId: $importId, replaces: $replaces);
    }

    /**
     * The import a popup produced, for a composer that never heard back from
     * it. 404 until the return happened, and for anyone else.
     */
    public function showReturn(Request $request, string $nonce): CanvaReturnResource
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $entry = Cache::get($this->returnKey($user, $nonce));

        abort_if(! is_array($entry)
            || data_get($entry, 'user_id') !== $user->id
            || data_get($entry, 'workspace_id') !== $workspace->id, HttpResponse::HTTP_NOT_FOUND);

        return new CanvaReturnResource($entry);
    }

    /**
     * The correlation entry this JWT points at, when the token is meant for
     * this integration and the design was opened by this user in this
     * workspace.
     *
     * @param  array<string, mixed>  $claims
     * @return array{user_id: string, workspace_id: string, connection_id: string, nonce: string, replaces_media_id: ?string}|null
     */
    private function ownCorrelation(array $claims, User $user, Workspace $workspace): ?array
    {
        $key = (string) data_get($claims, 'correlation_state', '');

        if (! in_array((string) config('services.canva.client_id'), (array) data_get($claims, 'aud', []), true)
            || data_get($claims, 'type') !== self::RETURN_JWT_TYPE
            || (int) data_get($claims, 'exp', 0) <= now()->getTimestamp()
            || (string) data_get($claims, 'jti', '') === ''
            || $key === '') {
            return null;
        }

        $correlation = Cache::get($this->correlationKey($key));

        if (! is_array($correlation)
            || data_get($correlation, 'user_id') !== $user->id
            || data_get($correlation, 'workspace_id') !== $workspace->id) {
            return null;
        }

        return $correlation;
    }

    /**
     * Whether the Canva user and team that clicked "Return" are the ones
     * this user's connection signed in as.
     *
     * @param  array<string, mixed>  $claims
     */
    private function returnedBy(array $claims, User $user, string $connectionId): bool
    {
        $connection = MediaSourceConnection::query()
            ->where('user_id', $user->id)
            ->whereKey($connectionId)
            ->first();

        return $connection !== null
            && filled($connection->external_user_id)
            && $connection->external_user_id === data_get($claims, 'sub')
            && $connection->external_team_id === data_get($claims, 'team_id');
    }

    private function startOAuth(Request $request, string $nonce, ?CanvaPreset $preset = null, ?Media $media = null, ?string $designId = null): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put(self::OAUTH_SESSION_KEY, [
            'state' => $state,
            'verifier' => $verifier,
            'preset' => $preset?->value,
            'media_id' => $media?->id,
            'design_id' => $designId,
            'nonce' => $nonce,
            'workspace_id' => $request->user()->currentWorkspace->id,
        ]);

        return redirect()->away($this->canva->authorizeUrl($state, $challenge));
    }

    private function openDesign(User $user, Workspace $workspace, MediaSourceConnection $connection, CanvaPreset $preset, string $nonce): RedirectResponse
    {
        $design = $this->canva->createDesign($this->canva->freshAccessToken($connection), $preset);

        return $this->redirectToEditor($user, $workspace, $connection, (string) data_get($design, 'edit_url'), $nonce, null);
    }

    private function openExistingDesign(User $user, Workspace $workspace, MediaSourceConnection $connection, string $designId, string $mediaId, string $nonce): RedirectResponse
    {
        $design = $this->canva->design($this->canva->freshAccessToken($connection), $designId);

        return $this->redirectToEditor($user, $workspace, $connection, (string) data_get($design, 'edit_url'), $nonce, $mediaId);
    }

    private function redirectToEditor(User $user, Workspace $workspace, MediaSourceConnection $connection, string $editUrl, string $nonce, ?string $replacesMediaId): RedirectResponse
    {
        if (parse_url($editUrl, PHP_URL_SCHEME) !== 'https'
            || str_contains($editUrl, '\\')
            || parse_url($editUrl, PHP_URL_USER) !== null
            || ! $this->importer->hostAllowed($editUrl, Source::Canva->editorHosts())) {
            throw new RuntimeException('Canva returned an edit_url outside the configured editor hosts.');
        }

        $key = Str::random(40);

        Cache::put($this->correlationKey($key), [
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'connection_id' => $connection->id,
            'nonce' => $nonce,
            'replaces_media_id' => $replacesMediaId,
        ], self::CORRELATION_TTL_SECONDS);

        $separator = str_contains($editUrl, '?') ? '&' : '?';

        return redirect()->away("{$editUrl}{$separator}correlation_state={$key}");
    }

    private function correlationKey(string $key): string
    {
        return "canva-design:{$key}";
    }

    private function returnKey(User $user, string $nonce): string
    {
        return "canva-return:{$user->id}:{$nonce}";
    }

    private function popup(bool $success, ?string $nonce, ?string $messageKey = null, ?string $importId = null, ?string $replaces = null): Response
    {
        return Inertia::render('integrations/MediaSourcePopup', [
            'source' => Source::Canva->value,
            'nonce' => $nonce,
            'success' => $success,
            'importId' => $importId,
            'replaces' => $replaces,
            'message' => $messageKey === null ? null : __($messageKey),
        ]);
    }
}
