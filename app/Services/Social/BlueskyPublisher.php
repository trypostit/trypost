<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\BlueskyPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Brand\SafeHttpFetcher;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\LinkCard\LinkCardFetcher;
use App\Services\Social\LinkCard\LinkCardMetadata;
use App\Support\UrlDetector;
use Carbon\CarbonInterface;
use Exception;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class BlueskyPublisher
{
    use HasSocialHttpClient;

    /** Seconds allowed for a remote media download (large videos need time). */
    private const DOWNLOAD_TIMEOUT = 600;

    /** Short timeout for the card thumbnail download (attacker-influenceable og:image, not a large media upload). */
    private const THUMB_DOWNLOAD_TIMEOUT = 15;

    /** Re-upload a transiently-failing transcode this many times before giving up. */
    private const VIDEO_UPLOAD_ATTEMPTS = 3;

    /** Poll getJobStatus up to this many times (× the configured interval) before timing out. */
    private const VIDEO_POLL_MAX_ATTEMPTS = 150;

    /** Wall-clock budget (seconds) for the whole upload+poll+retry flow, kept under the 600s job timeout so a stuck transcode degrades to text instead of being killed mid-flight. */
    private const VIDEO_PROCESSING_BUDGET = 420;

    private const JOB_STATE_COMPLETED = 'JOB_STATE_COMPLETED';

    private const JOB_STATE_FAILED = 'JOB_STATE_FAILED';

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $account = $postPlatform->socialAccount;
        $service = $account->meta['service'] ?? config('trypost.platforms.bluesky.default_service');

        // Refresh token if needed
        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $medias = $postPlatform->post->mediaItems;
        $embed = null;

        // Upload images if present (max 4)
        if ($medias->count() > 0) {
            $images = [];
            foreach ($medias->take(4) as $media) {
                if ($media->isImage()) {
                    $blob = $this->uploadBlob($account, $service, $media->url, $media->mime_type);
                    if ($blob) {
                        $images[] = [
                            'alt' => $media->altTextFor(Platform::Bluesky) ?? '',
                            'image' => $blob,
                        ];
                    }
                }
            }

            if (count($images) > 0) {
                $embed = [
                    '$type' => BlueskyLexicon::EMBED_IMAGES,
                    'images' => $images,
                ];
            }
        }

        // A post carries either images or a single video, never both. Only look
        // for a video when no image embed was built (mirrors the official client).
        if ($embed === null) {
            $video = $medias->first(fn ($media) => $media->isVideo());

            if ($video) {
                $videoBlob = $this->uploadVideo($account, $service, $video->url, $video->mime_type);

                if ($videoBlob) {
                    $embed = [
                        '$type' => BlueskyLexicon::EMBED_VIDEO,
                        'video' => $videoBlob,
                    ];
                }
            }
        }

        // No image or video embed, so a bare link can carry a preview card.
        // Bluesky does not hydrate cards server-side: the client must attach an
        // app.bsky.embed.external built from the page's OpenGraph metadata.
        if ($embed === null && $medias->isEmpty() && $content !== null) {
            $embed = $this->buildExternalEmbed($postPlatform->socialAccount, $service, $content);
        }

        // Parse facets (links, mentions, hashtags) from text
        $text = $content ?? '';
        $facets = $this->parseFacets($text);

        // Create post record
        $record = [
            '$type' => BlueskyLexicon::FEED_POST,
            'text' => $text,
            'createdAt' => now()->toIso8601ZuluString(),
        ];

        if ($embed) {
            $record['embed'] = $embed;
        }

        if (! empty($facets)) {
            $record['facets'] = $facets;
        }

        $response = $this->socialHttp()->withToken($account->access_token)
            ->post("{$service}/xrpc/".BlueskyLexicon::CREATE_RECORD, [
                'repo' => $account->platform_user_id,
                'collection' => BlueskyLexicon::FEED_POST,
                'record' => $record,
            ]);

        if ($response->failed()) {
            Log::error('Bluesky post failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);

            $this->handleApiError($response);
        }

        $data = $response->json();

        // Extract post ID from URI (at://did/app.bsky.feed.post/xxx)
        $uri = data_get($data, 'uri');
        $postId = basename($uri);

        return [
            'id' => $postId,
            'url' => $this->buildPostUrl($account->username, $postId),
        ];
    }

    /**
     * Build an app.bsky.embed.external card for the first link in the text, or
     * null when there is no link, the scrape fails, or the page has no metadata.
     * The og:image is re-uploaded as the card thumb because Bluesky's `thumb`
     * only accepts a blob, never an external URL. Any failure degrades to null
     * so the post still publishes with just the link facet.
     */
    private function buildExternalEmbed(SocialAccount $account, string $service, string $content): ?array
    {
        $card = app(LinkCardFetcher::class)->fetch($content);

        if ($card === null) {
            return null;
        }

        $external = [
            'uri' => $card->uri,
            'title' => $card->title,
            'description' => $card->description,
        ];

        $thumb = $this->uploadCardThumb($account, $service, $card);

        if ($thumb !== null) {
            $external['thumb'] = $thumb;
        }

        return [
            '$type' => BlueskyLexicon::EMBED_EXTERNAL,
            'external' => $external,
        ];
    }

    /**
     * Download the card's og:image and upload it as a blob for the thumb.
     * Returns null (card renders without a thumbnail) when there is no image or
     * the upload fails. A JPEG hint routes it through the image optimizer, which
     * re-encodes any static image and enforces Bluesky's 1MB blob limit.
     */
    private function uploadCardThumb(SocialAccount $account, string $service, LinkCardMetadata $card): ?array
    {
        if ($card->imageUrl === null) {
            return null;
        }

        try {
            app(SafeHttpFetcher::class)->guardAgainstSsrf($card->imageUrl);
        } catch (RuntimeException) {
            return null;
        }

        return $this->uploadBlob($account, $service, $card->imageUrl, 'image/jpeg', self::THUMB_DOWNLOAD_TIMEOUT, followRedirects: false);
    }

    private function uploadBlob(SocialAccount $account, string $service, string $url, string $mimeType, int $downloadTimeout = self::DOWNLOAD_TIMEOUT, bool $followRedirects = true): ?array
    {
        $tempFile = $this->downloadToTempFile($url, 'bsky_blob_', $downloadTimeout, $followRedirects);

        if ($tempFile === null) {
            return null;
        }

        try {
            // Optimize images for Bluesky's 1MB limit (GIFs are passed through untouched).
            if (MediaType::classify($mimeType) === MediaType::Image && ! MediaType::isGif($mimeType)) {
                $optimizedPath = app(MediaOptimizer::class)->optimizeImage($tempFile, Platform::Bluesky);
                @unlink($tempFile);
                $tempFile = $optimizedPath;
                $mimeType = 'image/jpeg';
            }

            $stream = fopen($tempFile, 'r');

            if ($stream === false) {
                Log::error('Bluesky could not open media file for upload', ['file' => $tempFile]);

                return null;
            }

            $response = $this->socialHttp()->withToken($account->access_token)
                ->withHeaders(['Content-Type' => $mimeType])
                ->withBody($stream, $mimeType)
                ->post("{$service}/xrpc/".BlueskyLexicon::UPLOAD_BLOB);

            if (is_resource($stream)) {
                fclose($stream);
            }

            if ($response->failed()) {
                Log::error('Bluesky blob upload failed', [
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                return null;
            }

            return data_get($response->json(), 'blob');
        } catch (Throwable $e) {
            Log::error('Bluesky blob upload exception', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return null;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * Download a remote media file to a temp file. Returns the temp path, or
     * null (after cleaning up) if the temp file can't be created, the download
     * fails, or the downloaded file is empty.
     *
     * $followRedirects defaults to true for the media/video paths, which
     * download from our own storage/CDN URLs. The card thumb path passes
     * false because the source is an attacker-influenceable og:image that
     * was only guarded against SSRF on its original URL — a redirect on that
     * hop must not be followed without re-guarding, so it is simply not
     * followed at all (the thumb degrades to null instead).
     */
    private function downloadToTempFile(string $url, string $prefix, int $timeoutSeconds = self::DOWNLOAD_TIMEOUT, bool $followRedirects = true): ?string
    {
        $tempFile = tempnam(sys_get_temp_dir(), $prefix);

        if ($tempFile === false) {
            Log::error('Bluesky could not create temp file for download', ['url' => $url]);

            return null;
        }

        try {
            $options = ['sink' => $tempFile];

            if (! $followRedirects) {
                $options['allow_redirects'] = false;
            }

            $response = Http::withOptions($options)->timeout($timeoutSeconds)->get($url);

            if ($response->failed()) {
                throw new Exception('HTTP '.$response->status());
            }

            $size = filesize($tempFile);

            if ($size === false || $size === 0) {
                throw new Exception('downloaded file is empty');
            }

            return $tempFile;
        } catch (Throwable $e) {
            Log::error('Bluesky media download failed', ['url' => $url, 'error' => $e->getMessage()]);
            @unlink($tempFile);

            return null;
        }
    }

    /**
     * Upload a video to Bluesky and return the processed blob for embedding.
     *
     * Unlike images, video does not go to the PDS via uploadBlob. It is sent to
     * the separate video service (video.bsky.app), which transcodes it and
     * stores the resulting blob on the account's PDS. The flow is:
     *   1. resolve the account's real PDS host (for the upload-token audience),
     *   2. mint two service-auth tokens — one for the video service to write
     *      the blob back to the PDS (uploadBlob), one to poll job status,
     *   3. POST the bytes to app.bsky.video.uploadVideo,
     *   4. poll app.bsky.video.getJobStatus until the blob is ready,
     *      retrying the whole upload a few times on a transient transcode failure.
     *
     * Returns null on any failure so the post still publishes as text rather
     * than crashing the whole job (mirrors uploadBlob()).
     */
    private function uploadVideo(SocialAccount $account, string $service, string $url, ?string $mimeType): ?array
    {
        $tempFile = $this->downloadToTempFile($url, 'bsky_video_');

        if ($tempFile === null) {
            return null;
        }

        try {
            $fileSize = filesize($tempFile);

            // Bluesky caps videos at 100MB; skip oversized files rather than
            // burning an upload that the service will reject.
            if ($fileSize > (int) config('trypost.platforms.bluesky.video_max_bytes')) {
                Log::error('Bluesky video exceeds size limit', ['url' => $url, 'size' => $fileSize]);

                return null;
            }

            $pds = $this->resolvePdsEndpoint($account, $service);
            $pdsHost = parse_url($pds, PHP_URL_HOST);

            if (! is_string($pdsHost) || $pdsHost === '') {
                Log::error('Bluesky could not resolve PDS host for video upload', ['did' => $account->platform_user_id]);

                return null;
            }

            // Two service-auth tokens, minted once (valid 30 min) and reused
            // across retries:
            //   - upload: lets the video service write the blob back to the
            //     user's PDS, so its audience is the PDS itself;
            //   - status: lets us poll the video service for the transcode job.
            $uploadToken = $this->getServiceAuth($account, $pds, "did:web:{$pdsHost}", BlueskyLexicon::UPLOAD_BLOB);
            $statusToken = $this->getServiceAuth($account, $pds, (string) config('trypost.platforms.bluesky.video_service_did'), BlueskyLexicon::VIDEO_GET_JOB_STATUS);

            if ($uploadToken === null || $statusToken === null) {
                return null;
            }

            // Bound the whole upload+poll+retry flow to a wall-clock budget that
            // stays under the queue job timeout: a stuck transcode must give up
            // and let the post publish as text, not run the worker to its
            // timeout (which would drop the post entirely on a $tries=1 job).
            $deadline = now()->addSeconds(self::VIDEO_PROCESSING_BUDGET);

            // The transcoder occasionally fails a job transiently
            // (JOB_STATE_FAILED "Failed to process video") even for valid input.
            // Re-uploading starts a fresh job, so retry until the budget runs out.
            for ($attempt = 1; $attempt <= self::VIDEO_UPLOAD_ATTEMPTS && now()->lessThan($deadline); $attempt++) {
                $blob = $this->attemptVideoUpload($account, $uploadToken, $statusToken, $tempFile, $mimeType, $deadline);

                if ($blob !== null) {
                    return $blob;
                }

                if ($attempt < self::VIDEO_UPLOAD_ATTEMPTS) {
                    Log::warning('Bluesky video upload attempt failed, retrying', [
                        'attempt' => $attempt,
                        'url' => $url,
                    ]);
                }
            }

            return null;
        } catch (Throwable $e) {
            Log::error('Bluesky video upload exception', [
                'error' => $e->getMessage(),
                'url' => $url,
            ]);

            return null;
        } finally {
            @unlink($tempFile);
        }
    }

    /**
     * A single upload-and-poll attempt against the video service. Returns the
     * processed blob, or null if this attempt failed (so the caller can retry).
     */
    private function attemptVideoUpload(SocialAccount $account, string $uploadToken, string $statusToken, string $tempFile, ?string $mimeType, CarbonInterface $deadline): ?array
    {
        $stream = fopen($tempFile, 'r');

        if ($stream === false) {
            Log::error('Bluesky could not open video file for upload', ['file' => $tempFile]);

            return null;
        }

        [$contentType, $extension] = $this->videoUploadFormat($mimeType);
        $videoService = (string) config('trypost.platforms.bluesky.video_service');
        $name = bin2hex(random_bytes(8)).'.'.$extension;
        $uploadUrl = "{$videoService}/xrpc/".BlueskyLexicon::VIDEO_UPLOAD
            .'?did='.rawurlencode($account->platform_user_id).'&name='.rawurlencode($name);

        $response = $this->socialHttp()->withToken($uploadToken)
            ->withHeaders(['Content-Type' => $contentType])
            ->withBody($stream, $contentType)
            ->post($uploadUrl);

        if (is_resource($stream)) {
            fclose($stream);
        }

        // A re-upload of identical bytes returns 409 with the already-finished
        // job, whose blob we can embed directly.
        if ($response->failed() && $response->status() !== 409) {
            Log::error('Bluesky video upload failed', [
                'status' => $response->status(),
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        $jobStatus = $this->unwrapJobStatus($response->json());

        if ($blob = data_get($jobStatus, 'blob')) {
            return $blob;
        }

        $jobId = data_get($jobStatus, 'jobId');

        if (! is_string($jobId) || $jobId === '') {
            Log::error('Bluesky video upload returned no jobId', [
                'body' => $this->redactResponseBody($response->body()),
            ]);

            return null;
        }

        return $this->pollVideoJob($statusToken, $jobId, $deadline);
    }

    /**
     * Poll the video service until the transcode job finishes, then return its
     * blob. Returns null if the job fails or never completes within the shared
     * deadline. The status token is minted by the caller and reused per poll.
     */
    private function pollVideoJob(string $statusToken, string $jobId, CarbonInterface $deadline): ?array
    {
        $statusUrl = (string) config('trypost.platforms.bluesky.video_service').'/xrpc/'.BlueskyLexicon::VIDEO_GET_JOB_STATUS;
        $intervalSeconds = (int) config('trypost.platforms.bluesky.video_poll_seconds');

        // Processing usually finishes within seconds. State is checked before
        // sleeping so an already-complete job returns at once. The attempt cap
        // and the wall-clock deadline both bound the loop.
        for ($attempt = 0; $attempt < self::VIDEO_POLL_MAX_ATTEMPTS && now()->lessThan($deadline); $attempt++) {
            $response = $this->socialHttp()->withToken($statusToken)
                ->get($statusUrl, ['jobId' => $jobId]);

            // Bail on a hard error (e.g. expired/invalid token) instead of
            // sleeping to the timeout and masking the real failure.
            if ($response->failed()) {
                Log::error('Bluesky getJobStatus failed', [
                    'jobId' => $jobId,
                    'status' => $response->status(),
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                return null;
            }

            $jobStatus = $this->unwrapJobStatus($response->json());
            $state = data_get($jobStatus, 'state');

            if ($state === self::JOB_STATE_COMPLETED && ($blob = data_get($jobStatus, 'blob'))) {
                return $blob;
            }

            if ($state === self::JOB_STATE_FAILED) {
                Log::error('Bluesky video processing failed', [
                    'jobId' => $jobId,
                    'message' => data_get($jobStatus, 'message'),
                ]);

                return null;
            }

            sleep($intervalSeconds);
        }

        Log::error('Bluesky video processing timed out', ['jobId' => $jobId]);

        return null;
    }

    /**
     * uploadVideo returns the job status at the top level, while getJobStatus
     * wraps it under a `jobStatus` key. Fall back to the top level only when the
     * key is absent (null), not when it's present-but-empty.
     */
    private function unwrapJobStatus(mixed $body): mixed
    {
        return data_get($body, 'jobStatus') ?? $body;
    }

    /**
     * Map a video mime type to the [Content-Type, file extension] the upload
     * should carry. Bluesky accepts mp4, mpeg, webm and mov; anything else
     * (or an unknown mime) is sent as mp4 and left to the transcoder.
     *
     * @return array{0: string, 1: string}
     */
    private function videoUploadFormat(?string $mimeType): array
    {
        return match ($mimeType) {
            'video/mpeg' => ['video/mpeg', 'mpeg'],
            'video/webm' => ['video/webm', 'webm'],
            'video/quicktime' => ['video/quicktime', 'mov'],
            default => ['video/mp4', 'mp4'],
        };
    }

    /**
     * Mint a short-lived service-auth token (com.atproto.server.getServiceAuth)
     * scoped to a single audience + method, used to authorize the video service.
     */
    private function getServiceAuth(SocialAccount $account, string $pds, string $aud, string $lxm): ?string
    {
        try {
            $response = $this->socialHttp()->withToken($account->access_token)
                ->get("{$pds}/xrpc/".BlueskyLexicon::GET_SERVICE_AUTH, [
                    'aud' => $aud,
                    'lxm' => $lxm,
                    'exp' => now()->addMinutes(30)->timestamp,
                ]);

            if ($response->failed()) {
                Log::error('Bluesky getServiceAuth failed', [
                    'status' => $response->status(),
                    'lxm' => $lxm,
                    'body' => $this->redactResponseBody($response->body()),
                ]);

                return null;
            }

            $token = data_get($response->json(), 'token');

            return is_string($token) && $token !== '' ? $token : null;
        } catch (Throwable $e) {
            Log::error('Bluesky getServiceAuth exception', ['error' => $e->getMessage(), 'lxm' => $lxm]);

            return null;
        }
    }

    /**
     * Resolve the account's real PDS service endpoint from its DID document.
     * Bluesky entryway accounts store the entryway URL as `service`, but video
     * service-auth must be scoped to the actual PDS host (e.g. *.host.bsky.network).
     * Falls back to the stored service URL when the DID document is unavailable.
     */
    private function resolvePdsEndpoint(SocialAccount $account, string $service): string
    {
        $did = (string) $account->platform_user_id;

        try {
            $docUrl = null;
            if (str_starts_with($did, 'did:plc:')) {
                $directory = (string) config('trypost.platforms.bluesky.plc_directory');
                $docUrl = "{$directory}/".rawurlencode($did);
            } elseif (str_starts_with($did, 'did:web:')) {
                // Per the did:web spec, colon-separated segments map to a host
                // plus optional path; a bare host uses /.well-known/did.json.
                $segments = array_map('rawurldecode', explode(':', substr($did, strlen('did:web:'))));
                $host = array_shift($segments);
                $path = $segments === [] ? '/.well-known/did.json' : '/'.implode('/', $segments).'/did.json';
                $docUrl = "https://{$host}{$path}";
            }

            if ($docUrl !== null) {
                $response = $this->socialHttp()->get($docUrl);

                if ($response->successful()) {
                    $services = data_get($response->json(), 'service', []);
                    foreach ($services as $entry) {
                        $type = data_get($entry, 'type');
                        $id = data_get($entry, 'id');
                        if ($type === 'AtprotoPersonalDataServer' || $id === '#atproto_pds') {
                            $endpoint = data_get($entry, 'serviceEndpoint');
                            if (is_string($endpoint) && $endpoint !== '') {
                                return $endpoint;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('Bluesky PDS resolution failed', ['did' => $did, 'error' => $e->getMessage()]);
        }

        return $service;
    }

    private function parseFacets(string $text): array
    {
        $facets = [];

        // Parse URLs
        preg_match_all(
            UrlDetector::URL_PATTERN,
            $text,
            $urlMatches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($urlMatches[0] as $match) {
            $url = UrlDetector::trimTrailingPunctuation($match[0]);
            $start = (int) $match[1];
            $end = $start + strlen($url);

            $facets[] = [
                'index' => [
                    'byteStart' => $start,
                    'byteEnd' => $end,
                ],
                'features' => [
                    [
                        '$type' => BlueskyLexicon::FACET_LINK,
                        'uri' => $url,
                    ],
                ],
            ];
        }

        // Parse mentions (@handle.bsky.social)
        preg_match_all(
            '/@([a-zA-Z0-9]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]([a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?/u',
            $text,
            $mentionMatches,
            PREG_OFFSET_CAPTURE
        );

        $didCache = [];
        foreach ($mentionMatches[0] as $match) {
            $mention = $match[0];
            $handle = substr($mention, 1); // Remove @

            // A mention facet needs the target's DID, not the handle; skip it if unresolvable.
            // Cache by key (not ??) so an unresolvable handle is resolved once, not per occurrence.
            if (! array_key_exists($handle, $didCache)) {
                $didCache[$handle] = $this->resolveHandleToDid($handle);
            }
            $did = $didCache[$handle];
            if ($did === null) {
                continue;
            }

            $start = (int) $match[1];
            $end = $start + strlen($mention);

            $facets[] = [
                'index' => [
                    'byteStart' => $start,
                    'byteEnd' => $end,
                ],
                'features' => [
                    [
                        '$type' => BlueskyLexicon::FACET_MENTION,
                        'did' => $did,
                    ],
                ],
            ];
        }

        // Parse hashtags (#tag)
        preg_match_all(
            '/#[^\s\p{P}]+/u',
            $text,
            $hashtagMatches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($hashtagMatches[0] as $match) {
            $hashtag = $match[0];
            $tag = substr($hashtag, 1); // Remove #
            $start = (int) $match[1];
            $end = $start + strlen($hashtag);

            $facets[] = [
                'index' => [
                    'byteStart' => $start,
                    'byteEnd' => $end,
                ],
                'features' => [
                    [
                        '$type' => BlueskyLexicon::FACET_TAG,
                        'tag' => $tag,
                    ],
                ],
            ];
        }

        return $facets;
    }

    /**
     * Resolve a Bluesky handle to its DID via com.atproto.identity.resolveHandle.
     *
     * resolveHandle is a public read served by the AppView (no auth). Returns
     * null on any failure so the caller can skip the mention facet and publish
     * the @handle as plain text instead of an invalid record.
     */
    private function resolveHandleToDid(string $handle): ?string
    {
        $appView = (string) config('trypost.platforms.bluesky.public_appview');

        try {
            $response = $this->socialHttp()->get(
                "{$appView}/xrpc/".BlueskyLexicon::RESOLVE_HANDLE,
                ['handle' => $handle],
            );

            $did = $response->successful() ? data_get($response->json(), 'did') : null;

            return is_string($did) && str_starts_with($did, 'did:') ? $did : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function buildPostUrl(string $handle, string $postId): string
    {
        $webApp = (string) config('trypost.platforms.bluesky.web_app');

        return "{$webApp}/profile/{$handle}/post/{$postId}";
    }

    private function handleApiError(Response $response): never
    {
        throw BlueskyPublishException::fromApiResponse($response);
    }
}
