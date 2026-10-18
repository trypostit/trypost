<?php

declare(strict_types=1);

namespace App\Services\Inbox\Meta;

use App\Dto\Inbox\InboxReaction;
use App\Dto\Inbox\MetaMessageParts;
use App\Enums\Inbox\DirectMessageContent;
use Illuminate\Support\Collection;

/**
 * Maps what a Meta direct message carries (Instagram Login, Instagram
 * through a Facebook Page and Messenger share the shapes) into the media,
 * content and reactions the inbox stores. Only documented fields are read;
 * anything else a message carries is content Meta cannot show.
 *
 * @see https://developers.facebook.com/docs/graph-api/reference/message
 * @see https://developers.facebook.com/docs/messenger-platform/reference/webhook-events/messages
 * @see https://developers.facebook.com/documentation/instagram-platform/webhooks/examples.md
 * @see https://developers.facebook.com/documentation/instagram-platform/webhooks/new.md
 */
class MetaMessageContent
{
    public const string NODE_FIELDS = 'id,created_time,from,to,message,attachments,shares{link,name,description},story,reactions,is_unsupported';

    private const array WEBHOOK_MEDIA_TYPES = ['image' => 'image', 'sticker' => 'image', 'video' => 'video'];

    private const array WEBHOOK_POST_TYPES = ['ig_post' => 'image', 'post' => 'image', 'share' => 'image', 'ig_reel' => 'video', 'reel' => 'video'];

    private const array WEBHOOK_FILE_TYPES = ['audio', 'file'];

    /**
     * A message read from the Message node of the Conversations API.
     *
     * @param  array<string, mixed>  $detail
     */
    public static function fromNode(array $detail): MetaMessageParts
    {
        $attachments = collect((array) data_get($detail, 'attachments.data', []));
        $media = $attachments->map(fn (mixed $attachment): ?array => self::nodeMedia($attachment))->filter()->values();
        $file = $attachments->first(fn (mixed $attachment): bool => filled(data_get($attachment, 'file_url')) && self::nodeMedia($attachment) === null);
        $unmapped = $attachments->contains(fn (mixed $attachment): bool => self::nodeMedia($attachment) === null && blank(data_get($attachment, 'file_url')));
        $share = data_get($detail, 'shares.data.0');

        $content = match (true) {
            (bool) data_get($detail, 'is_unsupported', false) => DirectMessageContent::Unsupported->toMeta(),
            filled(data_get($detail, 'story.mention')) => DirectMessageContent::StoryMention->toMeta(['url' => data_get($detail, 'story.mention.link')]),
            filled(data_get($detail, 'story.reply_to')) => DirectMessageContent::StoryReply->toMeta(['url' => data_get($detail, 'story.reply_to.link')]),
            filled(data_get($share, 'link')) => DirectMessageContent::SharedPost->toMeta(['url' => data_get($share, 'link'), 'title' => data_get($share, 'name') ?? data_get($share, 'description')]),
            filled($share) => DirectMessageContent::Unsupported->toMeta(),
            filled($file) => DirectMessageContent::Attachment->toMeta(['url' => data_get($file, 'file_url'), 'title' => data_get($file, 'name')]),
            $unmapped && $media->isEmpty() => DirectMessageContent::Unsupported->toMeta(),
            default => null,
        };

        return new MetaMessageParts($media->all(), $content, self::nodeReactions($detail));
    }

    /**
     * The `message` of a `messages` webhook event.
     *
     * @param  array<string, mixed>  $message
     */
    public static function fromWebhook(array $message): MetaMessageParts
    {
        $attachments = collect((array) data_get($message, 'attachments', []));
        $media = $attachments
            ->filter(fn (mixed $attachment): bool => array_key_exists((string) data_get($attachment, 'type'), self::WEBHOOK_MEDIA_TYPES) && filled(data_get($attachment, 'payload.url')))
            ->map(fn (array $attachment): array => ['type' => self::WEBHOOK_MEDIA_TYPES[data_get($attachment, 'type')], 'url' => (string) data_get($attachment, 'payload.url')])
            ->values();
        $mention = self::ofTypes($attachments, ['story_mention']);
        $post = self::ofTypes($attachments, array_keys(self::WEBHOOK_POST_TYPES));
        $link = self::ofTypes($attachments, ['fallback']);
        $file = self::ofTypes($attachments, self::WEBHOOK_FILE_TYPES);

        $content = match (true) {
            (bool) data_get($message, 'is_unsupported', false) => DirectMessageContent::Unsupported->toMeta(),
            filled($mention) => DirectMessageContent::StoryMention->toMeta(['url' => data_get($mention, 'payload.url')]),
            filled(data_get($message, 'reply_to.story')) => DirectMessageContent::StoryReply->toMeta(['url' => data_get($message, 'reply_to.story.url')]),
            filled($post) => DirectMessageContent::SharedPost->toMeta([
                'title' => data_get($post, 'payload.title'),
                'thumbnail_url' => data_get($post, 'payload.url'),
                'thumbnail_type' => self::WEBHOOK_POST_TYPES[data_get($post, 'type')],
            ]),
            filled($link) => DirectMessageContent::SharedPost->toMeta(['url' => data_get($link, 'payload.url'), 'title' => data_get($link, 'payload.title')]),
            filled($file) => DirectMessageContent::Attachment->toMeta(['url' => data_get($file, 'payload.url')]),
            $attachments->isNotEmpty() && $media->isEmpty() => DirectMessageContent::Unsupported->toMeta(),
            default => null,
        };

        return new MetaMessageParts($media->all(), $content);
    }

    /**
     * A `message_reactions` webhook event: who reacted to which message, and
     * with what (`null` when the reaction was removed).
     *
     * @param  array<string, mixed>  $event
     */
    public static function reactionFromWebhook(array $event, string $userId): ?InboxReaction
    {
        $messageId = data_get($event, 'reaction.mid');

        if (blank($messageId) || blank($userId)) {
            return null;
        }

        return new InboxReaction(
            (string) $messageId,
            $userId,
            data_get($event, 'reaction.action') === 'unreact' ? null : (data_get($event, 'reaction.emoji') ?: null),
        );
    }

    /** @return array{type: string, url: string}|null */
    private static function nodeMedia(mixed $attachment): ?array
    {
        $image = data_get($attachment, 'image_data.animated_gif_url') ?: data_get($attachment, 'image_data.url');
        $video = data_get($attachment, 'video_data.url');

        return match (true) {
            filled($image) => ['type' => 'image', 'url' => (string) $image],
            filled($video) => ['type' => 'video', 'url' => (string) $video],
            default => null,
        };
    }

    /**
     * Instagram names the emoji `emoji`, Messenger `reaction`.
     *
     * @param  array<string, mixed>  $detail
     * @return list<array{emoji: string, user_id: string}>
     */
    private static function nodeReactions(array $detail): array
    {
        return collect((array) data_get($detail, 'reactions.data', []))
            ->flatMap(fn (mixed $reaction): array => collect((array) data_get($reaction, 'users', []))
                ->map(fn (mixed $user): array => ['emoji' => (string) (data_get($reaction, 'emoji') ?? data_get($reaction, 'reaction')), 'user_id' => (string) data_get($user, 'id')])
                ->all())
            ->filter(fn (array $reaction): bool => filled($reaction['emoji']))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, mixed>  $attachments
     * @param  list<string>  $types
     */
    private static function ofTypes(Collection $attachments, array $types): mixed
    {
        return $attachments->first(fn (mixed $attachment): bool => in_array(data_get($attachment, 'type'), $types, true) && filled(data_get($attachment, 'payload.url')));
    }
}
