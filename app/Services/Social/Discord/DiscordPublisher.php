<?php

declare(strict_types=1);

namespace App\Services\Social\Discord;

use App\DataTransferObjects\MediaItem;
use App\Enums\Media\Type as MediaType;
use App\Enums\SocialAccount\Platform;
use App\Exceptions\Social\DiscordPublishException;
use App\Exceptions\Social\ErrorCategory;
use App\Models\PostPlatform;
use App\Services\Media\MediaOptimizer;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\ContentSanitizer;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Throwable;

class DiscordPublisher
{
    use HasSocialHttpClient;

    /**
     * Discord's hard message ceiling — also the maxContentLength() for the
     * platform, re-checked here because mentions are appended after validation.
     */
    private const MAX_MESSAGE_LENGTH = 2000;

    private const MAX_EMBED_COLOR = 0xFFFFFF;

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;
        $guildId = (string) $account->platform_user_id;
        $channelId = (string) data_get($postPlatform->meta, 'channel_id');

        if ($channelId === '') {
            throw new DiscordPublishException(
                userMessage: 'No Discord channel selected for this post.',
                category: ErrorCategory::Unknown,
            );
        }

        // Posting uses the global bot token, so the channel id is the only thing
        // scoping where a message lands — verify it belongs to THIS connected
        // server, otherwise a crafted channel_id could post into any server the
        // bot is in (including another workspace's).
        $this->guardChannelBelongsToGuild($guildId, $channelId);

        $content = $postPlatform->post->content
            ? app(ContentSanitizer::class)->sanitize($postPlatform->post->content, $postPlatform->platform)
            : '';

        $content = $this->appendMentions($content, $postPlatform);

        if (mb_strlen($content) > self::MAX_MESSAGE_LENGTH) {
            throw new DiscordPublishException(
                userMessage: 'The message (including mentions) exceeds Discord\'s 2000-character limit.',
                category: ErrorCategory::Unknown,
            );
        }

        $embeds = $this->buildEmbeds($postPlatform);
        $media = $postPlatform->post->mediaItems->take($postPlatform->platform->maxImages());

        $payload = array_filter([
            'content' => $content !== '' ? $content : null,
            'embeds' => $embeds !== [] ? $embeds : null,
            'allowed_mentions' => $this->allowedMentions($postPlatform),
        ], fn ($value) => $value !== null);

        $messageId = $media->isEmpty()
            ? $this->send($channelId, $payload)
            : $this->sendWithMedia($channelId, $payload, $media);

        return [
            'id' => $messageId,
            'url' => "https://discord.com/channels/{$guildId}/{$channelId}/{$messageId}",
        ];
    }

    private function guardChannelBelongsToGuild(string $guildId, string $channelId): void
    {
        $allowed = collect(app(DiscordClient::class)->channels($guildId))
            ->pluck('id')
            ->contains($channelId);

        if (! $allowed) {
            throw new DiscordPublishException(
                userMessage: 'The bot can\'t post in the selected channel. Make sure it still exists, belongs to this server, and the bot has permission to send messages there.',
                category: ErrorCategory::Permission,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $channelId, array $payload): string
    {
        return $this->messageId($this->bot()->post($this->endpoint($channelId), $payload));
    }

    /**
     * Downloads each media item, optimizes images for Discord, and uploads them
     * as multipart attachments alongside the JSON payload.
     *
     * @param  array<string, mixed>  $payload
     * @param  Collection<int, MediaItem>  $media
     */
    private function sendWithMedia(string $channelId, array $payload, Collection $media): string
    {
        $request = $this->bot();
        $attachments = [];
        $tempFiles = [];

        try {
            foreach ($media->values() as $index => $item) {
                $tempFile = $this->downloadMedia($item);
                $tempFiles[] = $tempFile;

                $filename = $item->original_filename ?: (basename($item->path) ?: "media-{$index}");
                $request = $request->attach("files[{$index}]", file_get_contents($tempFile), $filename);

                $attachment = ['id' => $index, 'filename' => $filename];

                $alt = $item->isImage() ? $item->altTextFor(Platform::Discord) : null;

                if ($alt !== null) {
                    $attachment['description'] = $alt;
                }

                $attachments[] = $attachment;
            }

            $payload['attachments'] = $attachments;

            return $this->messageId($request->post($this->endpoint($channelId), [
                'payload_json' => json_encode($payload),
            ]));
        } finally {
            foreach ($tempFiles as $tempFile) {
                @unlink($tempFile);
            }
        }
    }

    private function downloadMedia(MediaItem $item): string
    {
        $tempFile = tempnam(sys_get_temp_dir(), 'discord_media_');

        $response = Http::withOptions(['sink' => $tempFile])->timeout(120)->get($item->url);

        if ($response->failed() || (filesize($tempFile) ?: 0) === 0) {
            @unlink($tempFile);

            throw new DiscordPublishException(
                userMessage: 'Failed to download a media file for this Discord post.',
                category: ErrorCategory::MediaFormat,
            );
        }

        // Images are downsized to Discord's limit; videos/gifs are sent as-is.
        if ($item->isImage() && ! MediaType::isGif($item->mime_type)) {
            try {
                return app(MediaOptimizer::class)->optimizeImage($tempFile, Platform::Discord);
            } catch (Throwable) {
                throw new DiscordPublishException(
                    userMessage: 'Failed to process a media image for this Discord post.',
                    category: ErrorCategory::MediaFormat,
                );
            } finally {
                @unlink($tempFile);
            }
        }

        return $tempFile;
    }

    /**
     * Appends the post's Discord mention tokens to the content. Mentions live in
     * meta — not the shared content — so they only ping on Discord and don't leak
     * literal `<@id>` markup to other platforms.
     */
    private function appendMentions(string $content, PostPlatform $postPlatform): string
    {
        $tokens = $this->mentionTokens($postPlatform)->implode(' ');

        if ($tokens === '') {
            return $content;
        }

        return $content === '' ? $tokens : "{$content}\n\n{$tokens}";
    }

    /**
     * Builds an explicit allowed_mentions object from the post's mention chips so
     * ONLY those targets ping. Defaults to `parse: []` (suppress everything), so a
     * literal "@everyone" typed into the shared content never pings by accident.
     *
     * @return array<string, mixed>
     */
    private function allowedMentions(PostPlatform $postPlatform): array
    {
        $parse = [];
        $users = [];
        $roles = [];

        foreach ($this->mentionTokens($postPlatform) as $token) {
            if ($token === '@everyone' || $token === '@here') {
                $parse[] = 'everyone';
            } elseif (preg_match('/^<@&(\d+)>$/', $token, $matches)) {
                $roles[] = $matches[1];
            } elseif (preg_match('/^<@(\d+)>$/', $token, $matches)) {
                $users[] = $matches[1];
            }
        }

        $allowed = ['parse' => array_values(array_unique($parse))];

        if ($users !== []) {
            $allowed['users'] = array_values(array_unique($users));
        }

        if ($roles !== []) {
            $allowed['roles'] = array_values(array_unique($roles));
        }

        return $allowed;
    }

    /**
     * @return Collection<int, string>
     */
    private function mentionTokens(PostPlatform $postPlatform): Collection
    {
        return collect((array) data_get($postPlatform->meta, 'mentions', []))
            ->map(fn ($mention) => trim((string) data_get($mention, 'token')))
            ->filter()
            ->values();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildEmbeds(PostPlatform $postPlatform): array
    {
        return collect((array) data_get($postPlatform->meta, 'embeds', []))
            ->map(fn ($embed) => array_filter([
                'title' => data_get($embed, 'title'),
                'description' => data_get($embed, 'description'),
                'url' => data_get($embed, 'url'),
                'color' => $this->color(data_get($embed, 'color')),
                'image' => ($image = data_get($embed, 'image')) ? ['url' => $image] : null,
            ], fn ($value) => $value !== null && $value !== ''))
            ->filter(fn ($embed) => $embed !== [])
            ->values()
            ->all();
    }

    private function color(mixed $color): ?int
    {
        if (! is_string($color) || $color === '') {
            return null;
        }

        $hex = ltrim($color, '#');

        if (! ctype_xdigit($hex) || strlen($hex) !== 6) {
            return null;
        }

        return min((int) hexdec($hex), self::MAX_EMBED_COLOR);
    }

    private function endpoint(string $channelId): string
    {
        return config('trypost.platforms.discord.api')."/channels/{$channelId}/messages";
    }

    private function bot(): PendingRequest
    {
        return $this->socialHttp()
            ->withToken((string) config('trypost.platforms.discord.bot_token'), 'Bot');
    }

    private function messageId(Response $response): string
    {
        if ($response->failed()) {
            throw DiscordPublishException::fromApiResponse($response);
        }

        $id = (string) data_get($response->json(), 'id');

        if ($id === '') {
            throw new DiscordPublishException(
                userMessage: 'Discord returned an unexpected response with no message id.',
                category: ErrorCategory::Unknown,
            );
        }

        return $id;
    }
}
