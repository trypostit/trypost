<?php

declare(strict_types=1);

namespace App\Services\Social\Telegram;

use App\DataTransferObjects\MediaItem;
use App\Exceptions\Social\TelegramPublishException;
use App\Models\PostPlatform;
use App\Models\SocialAccount;
use App\Services\Social\Concerns\HasSocialHttpClient;
use App\Services\Social\ContentSanitizer;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;

class TelegramPublisher
{
    use HasSocialHttpClient;

    /**
     * Telegram caps media captions at 1024 chars (a text-only message allows
     * 4096). When the post is longer than a caption, the media is sent first and
     * the full text follows as its own message.
     */
    private const CAPTION_LIMIT = 1024;

    private const ALBUM_CHUNK = 10;

    public function publish(PostPlatform $postPlatform): array
    {
        $this->validateContentLength($postPlatform);

        $account = $postPlatform->socialAccount;
        $chatId = (string) data_get($account->meta, 'chat_id');

        $content = $postPlatform->resolvedContent()
            ? app(ContentSanitizer::class)->sanitize($postPlatform->resolvedContent(), $postPlatform->platform)
            : '';

        $media = $postPlatform->post->mediaItems->take(self::ALBUM_CHUNK);

        $messageId = $media->isEmpty()
            ? $this->sendText($chatId, $content)
            : $this->sendWithMedia($chatId, $content, $media);

        return [
            'id' => (string) $messageId,
            'url' => $this->buildPostUrl($account, $messageId),
        ];
    }

    private function sendText(string $chatId, string $text): int
    {
        $response = $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ]);

        return (int) data_get($response->json(), 'result.message_id');
    }

    private function sendWithMedia(string $chatId, string $content, Collection $media): int
    {
        $fitsCaption = mb_strlen($content) <= self::CAPTION_LIMIT;
        $caption = $fitsCaption ? $content : '';

        $items = $media->map(fn (MediaItem $item) => $this->telegramMedia($item))->values()->all();

        $messageId = count($items) === 1
            ? $this->sendSingleMedia($chatId, $items[0], $caption)
            : $this->sendMediaGroup($chatId, $items, $caption);

        // Long text can't ride along as a caption — send it as a follow-up message.
        if (! $fitsCaption) {
            $this->sendText($chatId, $content);
        }

        return $messageId;
    }

    /**
     * @param  array{type: TelegramMediaType, url: string}  $item
     */
    private function sendSingleMedia(string $chatId, array $item, string $caption): int
    {
        $type = $item['type'];

        $response = $this->call($type->sendMethod(), [
            'chat_id' => $chatId,
            $type->value => $item['url'],
            'caption' => $caption,
            'parse_mode' => 'HTML',
        ]);

        return (int) data_get($response->json(), 'result.message_id');
    }

    /**
     * @param  array<int, array{type: TelegramMediaType, url: string}>  $items
     */
    private function sendMediaGroup(string $chatId, array $items, string $caption): int
    {
        $group = [];

        foreach ($items as $index => $item) {
            $entry = ['type' => $item['type']->value, 'media' => $item['url']];

            if ($index === 0 && $caption !== '') {
                $entry['caption'] = $caption;
                $entry['parse_mode'] = 'HTML';
            }

            $group[] = $entry;
        }

        $response = $this->call('sendMediaGroup', [
            'chat_id' => $chatId,
            'media' => json_encode($group),
        ]);

        return (int) data_get($response->json(), 'result.0.message_id');
    }

    /**
     * @return array{type: TelegramMediaType, url: string}
     */
    private function telegramMedia(MediaItem $media): array
    {
        return ['type' => TelegramMediaType::for($media), 'url' => $media->url];
    }

    private function call(string $method, array $payload): Response
    {
        $response = $this->socialHttp()->post(TelegramApi::endpoint($method), $payload);

        if ($response->failed() || data_get($response->json(), 'ok') !== true) {
            $this->handleApiError($response);
        }

        return $response;
    }

    private function buildPostUrl(SocialAccount $account, int $messageId): string
    {
        $username = (string) data_get($account->meta, 'username');

        if ($username !== '') {
            return "https://t.me/{$username}/{$messageId}";
        }

        // Private channels: t.me/c/<id without the -100 prefix>/<message_id>.
        $internalId = preg_replace('/^-100/', '', (string) data_get($account->meta, 'chat_id'));

        return "https://t.me/c/{$internalId}/{$messageId}";
    }

    private function handleApiError(Response $response): never
    {
        throw TelegramPublishException::fromApiResponse($response);
    }
}
