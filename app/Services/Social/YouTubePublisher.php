<?php

declare(strict_types=1);

namespace App\Services\Social;

use App\Exceptions\Social\ErrorCategory;
use App\Exceptions\Social\YouTubePublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use Google\Client as GoogleClient;
use Google\Service\Exception;
use Google\Service\YouTube;
use Google\Service\YouTube\Video;
use Google\Service\YouTube\VideoSnippet;
use Google\Service\YouTube\VideoStatus;
use Google_Http_MediaFileUpload;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class YouTubePublisher
{
    use HasSocialHttpClient;

    private const CHUNK_SIZE = 10 * 1024 * 1024; // 10MB chunks

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $content = $postPlatform->post->content ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform) : null;

        $account = $postPlatform->socialAccount;

        if ($account->needsProactiveTokenRefresh()) {
            app(ConnectionVerifier::class)->refreshToken($account);
        }

        $media = $postPlatform->post->mediaItems;

        if ($media->isEmpty()) {
            throw new YouTubePublishException(
                userMessage: 'YouTube Shorts requires a video to publish.',
                category: ErrorCategory::MediaFormat,
            );
        }

        $firstMedia = $media->first();

        if (! $firstMedia->isVideo()) {
            throw new YouTubePublishException(
                userMessage: 'YouTube Shorts only supports video content.',
                category: ErrorCategory::MediaFormat,
            );
        }

        return $this->publishShort($postPlatform, $firstMedia, $account, $content);
    }

    private function createGoogleClient(SocialAccount $account): GoogleClient
    {
        $client = new GoogleClient;
        $client->setClientId(config('services.google.client_id'));
        $client->setClientSecret(config('services.google.client_secret'));

        $remainingSeconds = $account->token_expires_at
            ? max(0, (int) now()->diffInSeconds($account->token_expires_at, false))
            : 3600;

        $tokenData = [
            'access_token' => $account->access_token,
            'created' => time(),
            'expires_in' => $remainingSeconds,
        ];

        if ($account->refresh_token) {
            $tokenData['refresh_token'] = $account->refresh_token;
        }

        $client->setAccessToken($tokenData);

        return $client;
    }

    private function publishShort(PostPlatform $postPlatform, $media, SocialAccount $account, ?string $content): array
    {
        if (empty($content)) {
            throw new YouTubePublishException(
                userMessage: 'YouTube Shorts require a title. Please add text to your post.',
                category: ErrorCategory::ContentPolicy,
            );
        }

        $title = $this->buildTitle($content);
        $description = $content;

        $tempFile = tempnam(sys_get_temp_dir(), 'yt_upload_');
        $handle = null;

        try {
            // Download video to temp file (memory-safe)
            $downloadResponse = Http::withOptions(['sink' => $tempFile])
                ->timeout(600)
                ->get($media->url);

            if ($downloadResponse->failed()) {
                throw new YouTubePublishException(
                    userMessage: 'Failed to download video for YouTube upload: HTTP '.$downloadResponse->status(),
                    category: ErrorCategory::ServerError,
                );
            }

            $fileSize = filesize($tempFile);

            if ($fileSize === false || $fileSize < 1024) {
                throw new YouTubePublishException(
                    userMessage: 'Downloaded video is too small or empty ('.$fileSize.' bytes), aborting upload',
                    category: ErrorCategory::MediaFormat,
                );
            }

            // Set up Google Client with deferred mode for resumable upload
            $client = $this->createGoogleClient($account);
            $client->setDefer(true);

            $youtube = new YouTube($client);

            // Build video metadata
            $snippet = new VideoSnippet;
            $snippet->setTitle($title);
            $snippet->setDescription($description);
            $snippet->setCategoryId('22');

            $status = new VideoStatus;
            $status->setPrivacyStatus('public');
            $status->setSelfDeclaredMadeForKids(false);

            $video = new Video;
            $video->setSnippet($snippet);
            $video->setStatus($status);

            // Initialize resumable upload request
            $insertRequest = $youtube->videos->insert('snippet,status', $video);

            $mediaUpload = new Google_Http_MediaFileUpload(
                $client,
                $insertRequest,
                $media->mime_type ?: 'video/mp4',
                null,
                true,
                self::CHUNK_SIZE
            );
            $mediaUpload->setFileSize($fileSize);

            // Upload in chunks (memory-safe for large files)
            $uploadStatus = false;
            $handle = fopen($tempFile, 'r');

            if ($handle === false) {
                throw new YouTubePublishException(
                    userMessage: 'Failed to open temp file for YouTube upload',
                    category: ErrorCategory::ServerError,
                );
            }

            while (! $uploadStatus && ! feof($handle)) {
                $chunk = fread($handle, self::CHUNK_SIZE);
                $uploadStatus = $mediaUpload->nextChunk($chunk);
            }

            fclose($handle);
            $handle = null;

            $client->setDefer(false);

            if (! $uploadStatus instanceof Video) {
                throw new YouTubePublishException(
                    userMessage: 'YouTube upload failed: no video object returned',
                    category: ErrorCategory::ServerError,
                );
            }

            $videoId = $uploadStatus->getId();

            return [
                'id' => $videoId,
                'url' => "https://www.youtube.com/shorts/{$videoId}",
            ];
        } catch (Exception $e) {
            $this->handleGoogleError($e);
        } catch (\Throwable $e) {
            Log::error('YouTube upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        } finally {
            if ($handle !== null && is_resource($handle)) {
                fclose($handle);
            }

            @unlink($tempFile);
        }
    }

    private function buildTitle(string $content): string
    {
        $maxLength = 100;
        $shortsTag = ' #Shorts';
        $availableLength = $maxLength - strlen($shortsTag);

        $firstLine = explode("\n", $content)[0];
        $title = explode('.', $firstLine)[0];

        if (strlen($title) > $availableLength) {
            $title = substr($title, 0, $availableLength - 3).'...';
        }

        return $title.$shortsTag;
    }

    private function handleGoogleError(Exception $e): never
    {
        throw YouTubePublishException::fromGoogleException($e);
    }
}
