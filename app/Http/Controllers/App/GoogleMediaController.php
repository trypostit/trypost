<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Media\Source;
use App\Http\Requests\App\Integration\StartGoogleMediaRequest;
use App\Http\Resources\App\GoogleMediaReturnResource;
use App\Models\User;
use App\Services\Media\Sources\GoogleMediaOAuth;
use App\Services\Media\Sources\GooglePhotosPicker;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Google sign-in for the composer's Drive and Photos sources, in a popup:
 * our start route, Google's consent, our callback. The token never reaches
 * a URL or the popup page. The callback leaves the result in the cache
 * under the composer's nonce, and the composer claims it once:
 * Drive gets the short-lived token the Picker needs; Photos gets a picker
 * session whose token stays on the server.
 */
class GoogleMediaController extends Controller
{
    private const string OAUTH_SESSION_KEY = 'google_media_oauth';

    private const int RETURN_TTL_SECONDS = 600;

    private const int PKCE_TTL_SECONDS = 600;

    public function __construct(private readonly GoogleMediaOAuth $oauth, private readonly GooglePhotosPicker $picker) {}

    public function start(StartGoogleMediaRequest $request): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        Cache::put($this->verifierKey($request->user(), $state), Crypt::encryptString($verifier), self::PKCE_TTL_SECONDS);

        $request->session()->put(self::OAUTH_SESSION_KEY, [
            'state' => $state,
            'source' => $request->source()->value,
            'nonce' => $request->nonce(),
            'user_id' => $request->user()->id,
            'workspace_id' => $request->user()->currentWorkspace->id,
        ]);

        return redirect()->away($this->oauth->authorizeUrl($request->source(), $state, $challenge));
    }

    public function callback(Request $request): Response
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $pending = $request->session()->pull(self::OAUTH_SESSION_KEY);
        $state = (string) $request->query('state', '');
        $code = (string) $request->query('code', '');
        $nonce = data_get($pending, 'nonce');
        $source = Source::tryFrom((string) data_get($pending, 'source'));
        $source = $source !== null && array_key_exists($source->value, GoogleMediaOAuth::SCOPES) ? $source : null;

        if (! is_array($pending)
            || ! is_string($nonce)
            || $source === null
            || $state === ''
            || ! hash_equals((string) data_get($pending, 'state'), $state)
            || data_get($pending, 'user_id') !== $user->id
            || data_get($pending, 'workspace_id') !== $workspace->id) {
            if (is_string(data_get($pending, 'state'))) {
                Cache::forget($this->verifierKey($user, (string) data_get($pending, 'state')));
            }

            return $this->popup($source ?? Source::GoogleDrive, false, is_string($nonce) ? $nonce : null, 'posts.composer.media_sources.errors.google_connect_failed');
        }

        $verifier = $this->pullVerifier($user, $state);

        if (! $source->isEnabled()) {
            return $this->popup($source, false, $nonce, 'posts.composer.media_sources.errors.google_connect_failed');
        }

        if ($request->query('error') === 'access_denied') {
            return $this->popup($source, false, $nonce, 'posts.composer.media_sources.errors.scope_not_granted');
        }

        if ($code === '' || $verifier === null) {
            return $this->popup($source, false, $nonce, 'posts.composer.media_sources.errors.google_connect_failed');
        }

        try {
            $token = $this->oauth->exchangeCode($code, $verifier);
        } catch (Throwable) {
            return $this->popup($source, false, $nonce, 'posts.composer.media_sources.errors.google_connect_failed');
        }

        if (! in_array(GoogleMediaOAuth::scope($source), data_get($token, 'scopes'), true)) {
            return $this->popup($source, false, $nonce, 'posts.composer.media_sources.errors.scope_not_granted');
        }

        $accessToken = (string) data_get($token, 'access_token');
        $expiresIn = max(1, (int) data_get($token, 'expires_in'));
        $entry = ['user_id' => $user->id, 'workspace_id' => $workspace->id, 'source' => $source->value];

        if ($source === Source::GoogleDrive) {
            Cache::put($this->returnKey($user, $nonce), [
                ...$entry,
                'access_token' => Crypt::encryptString($accessToken),
                'expires_at' => now()->addSeconds($expiresIn)->getTimestamp(),
            ], min($expiresIn, self::RETURN_TTL_SECONDS));

            return $this->popup($source, true, $nonce);
        }

        try {
            $session = $this->picker->createSession($accessToken);
        } catch (Throwable) {
            return $this->popup($source, false, $nonce, 'posts.composer.media_sources.errors.google_connect_failed');
        }

        $pickerUri = (string) data_get($session, 'picker_uri');

        if (parse_url($pickerUri, PHP_URL_SCHEME) !== 'https') {
            return $this->popup($source, false, $nonce, 'posts.composer.media_sources.errors.google_connect_failed');
        }

        GooglePhotosPicker::rememberToken($user->id, (string) data_get($session, 'id'), $accessToken, $expiresIn);

        Cache::put($this->returnKey($user, $nonce), [
            ...$entry,
            'session_id' => data_get($session, 'id'),
            'polling' => data_get($session, 'polling'),
        ], min($expiresIn, self::RETURN_TTL_SECONDS));

        return $this->popup($source, true, $nonce, pickerUri: $pickerUri);
    }

    /**
     * What the popup produced, handed to the composer that opened it once.
     * 404 until the callback ran, after it was claimed, and for anyone else.
     */
    public function claim(Request $request, string $nonce): JsonResponse
    {
        $user = $request->user();
        $workspace = $user->currentWorkspace;

        $this->authorize('createPost', $workspace);

        $key = $this->returnKey($user, $nonce);
        $entry = Cache::get($key);

        abort_if(! is_array($entry)
            || data_get($entry, 'user_id') !== $user->id
            || data_get($entry, 'workspace_id') !== $workspace->id, HttpResponse::HTTP_NOT_FOUND);

        Cache::forget($key);

        if (data_get($entry, 'source') === Source::GoogleDrive->value) {
            try {
                $entry = [
                    'source' => Source::GoogleDrive->value,
                    'access_token' => Crypt::decryptString((string) data_get($entry, 'access_token')),
                    'expires_in' => max(0, (int) data_get($entry, 'expires_at') - now()->getTimestamp()),
                ];
            } catch (DecryptException) {
                abort(HttpResponse::HTTP_NOT_FOUND);
            }
        }

        return (new GoogleMediaReturnResource($entry))
            ->response()
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The PKCE verifier lives encrypted in the cache, keyed by its state and
     * user, so it never sits in the session payload Telescope records.
     */
    private function verifierKey(User $user, string $state): string
    {
        $stateHash = hash('sha256', $state);

        return "google-media-pkce:{$user->id}:{$stateHash}";
    }

    private function pullVerifier(User $user, string $state): ?string
    {
        $encrypted = Cache::pull($this->verifierKey($user, $state));

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            return null;
        }
    }

    private function returnKey(User $user, string $nonce): string
    {
        return "google-media-return:{$user->id}:{$nonce}";
    }

    private function popup(Source $source, bool $success, ?string $nonce, ?string $messageKey = null, ?string $pickerUri = null): Response
    {
        return Inertia::render('integrations/MediaSourcePopup', [
            'source' => $source->value,
            'nonce' => $nonce,
            'success' => $success,
            'importId' => null,
            'replaces' => null,
            'pickerUri' => $pickerUri,
            'message' => $messageKey === null ? null : __($messageKey),
        ]);
    }
}
