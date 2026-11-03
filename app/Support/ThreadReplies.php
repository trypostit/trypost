<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Dto\MediaItem;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Services\Social\ContentSanitizer;
use App\Support\Social\XPoll;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Replies that follow a post as a thread (`meta.thread_replies`), on the
 * networks that let an app reply to its own post with the scopes we hold.
 * Each reply is `{text, media}`; its media belongs to that reply and follows
 * the rules of the network's reply content type. A reply stored as a bare
 * string is a text-only reply. On X a reply may carry a poll instead of media
 * (`{text, media, poll}`), following `XPoll`'s rules with the reply text as
 * its question.
 */
class ThreadReplies
{
    public const int MAX_REPLIES = 24;

    /**
     * The content type a reply publishes as. A network chains replies only
     * when it has one.
     */
    public static function replyContentType(?Platform $platform): ?ContentType
    {
        return match ($platform) {
            Platform::Bluesky => ContentType::BlueskyPost,
            Platform::Mastodon => ContentType::MastodonPost,
            Platform::X => ContentType::XPost,
            default => null,
        };
    }

    public static function supports(?Platform $platform): bool
    {
        return self::replyContentType($platform) !== null;
    }

    /**
     * @return list<array{text: string, media: list<array<string, mixed>>, poll?: array{options: list<string>, duration_minutes?: int}}>
     */
    public static function of(mixed $meta): array
    {
        return array_values(array_map(
            self::reply(...),
            (array) data_get($meta, 'thread_replies', []),
        ));
    }

    /**
     * @return array{text: string, media: list<array<string, mixed>>, poll?: array{options: list<string>, duration_minutes?: int}}
     */
    public static function reply(mixed $reply): array
    {
        if (is_string($reply)) {
            return ['text' => $reply, 'media' => []];
        }

        $text = data_get($reply, 'text');
        $poll = data_get($reply, 'poll');

        $normalized = [
            'text' => is_string($text) ? $text : '',
            'media' => array_values(array_filter((array) data_get($reply, 'media', []), is_array(...))),
        ];

        if (is_array($poll) && filled($poll)) {
            $normalized['poll'] = XPoll::normalize($poll);
        }

        return $normalized;
    }

    /**
     * Whether a reply is a poll (an X reply only; `of()` drops an empty one).
     *
     * @param  array<string, mixed>  $reply
     */
    public static function hasPoll(array $reply): bool
    {
        return filled(data_get($reply, 'poll'));
    }

    /**
     * Replies without their polls, for a network other than X.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public static function withoutPolls(array $meta): array
    {
        if (blank(data_get($meta, 'thread_replies'))) {
            return $meta;
        }

        $meta['thread_replies'] = collect(self::of($meta))->map(fn (array $reply): array => Arr::except($reply, 'poll'))->all();

        return $meta;
    }

    /**
     * A reply poll no post may store, even as a draft: repeated choices.
     *
     * @return array{0: string, 1: string}|null [field, message]
     */
    public static function pollFormatViolation(mixed $meta): ?array
    {
        foreach (self::of($meta) as $index => $reply) {
            $violation = self::hasPoll($reply) ? XPoll::violation($reply) : null;

            if (filled($violation)) {
                return ["thread_replies.{$index}.{$violation[0]}", $violation[1]];
            }
        }

        return null;
    }

    /**
     * A reply poll carries no media, drafts included. Only X has reply polls;
     * any other network drops them on save.
     *
     * @return array<string, string>
     */
    public static function pollMediaErrors(?Platform $platform, mixed $meta): array
    {
        if ($platform !== Platform::X) {
            return [];
        }

        return collect(self::of($meta))
            ->filter(fn (array $reply): bool => self::hasPoll($reply) && filled($reply['media']))
            ->mapWithKeys(fn (array $reply, int $index): array => ["thread_replies.{$index}.media" => __('posts.errors.media_not_accepted')])
            ->all();
    }

    /**
     * @param  array{text: string, media: list<array<string, mixed>>}  $reply
     * @return Collection<int, MediaItem>
     */
    public static function mediaItems(array $reply): Collection
    {
        return collect($reply['media'])->map(fn (array $item): MediaItem => MediaItem::fromArray($item));
    }

    /**
     * Each reply is measured like the post itself: sanitized text plus what the
     * network counts besides it (the Mastodon content warning every reply repeats),
     * against the account's limit when the account is known (X long posts).
     * A reply needs text or media.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function violation(Platform|SocialAccount|null $target, mixed $meta): ?array
    {
        $platform = $target instanceof SocialAccount ? $target->platform : $target;
        $replies = self::of($meta);

        if ($replies === []) {
            return null;
        }

        if ($platform === null || ! self::supports($platform)) {
            return ['thread_replies', __('posts.form.thread.unsupported')];
        }

        $reserved = $platform->reservedLength(is_array($meta) ? $meta : null);
        $account = $target instanceof SocialAccount ? $target : null;

        foreach ($replies as $index => $reply) {
            $blank = blank(Str::trim($reply['text']));
            $poll = $platform === Platform::X && self::hasPoll($reply);

            if ($poll && $blank) {
                return ["thread_replies.{$index}", XPoll::questionViolation($reply['text'])];
            }

            if ($blank && $reply['media'] === []) {
                return ["thread_replies.{$index}", __('posts.form.thread.reply_empty')];
            }

            $type = $poll ? ContentType::XPoll : self::replyContentType($platform);
            $over = $blank ? 0 : ContentType::overflowFor($account, $platform, $type, app(ContentSanitizer::class)->displayText($reply['text'], $platform), $reserved);

            if ($over > 0) {
                return ["thread_replies.{$index}", __('posts.form.thread.reply_too_long', ['limit' => ContentType::maxContentLengthFor($account, $platform, $type) - $reserved, 'over' => $over])];
            }

            $pollViolation = $poll ? XPoll::violation($reply) ?? XPoll::requiredViolation($reply) : null;

            if (filled($pollViolation)) {
                return ["thread_replies.{$index}.{$pollViolation[0]}", $pollViolation[1]];
            }
        }

        return null;
    }

    /**
     * Each reply's media against the reply content type, keyed
     * `thread_replies.{n}.media`.
     *
     * @return array<string, string>
     */
    public static function mediaErrors(?Platform $platform, mixed $meta, ?Workspace $workspace): array
    {
        $contentType = self::replyContentType($platform);

        if ($contentType === null) {
            return [];
        }

        $errors = [];

        foreach (self::of($meta) as $index => $reply) {
            $key = "thread_replies.{$index}.media";
            $count = count($reply['media']);

            if ($count > $contentType->maxMediaCount()) {
                $errors[$key] = __('posts.form.warnings.max_files_exceeded', ['max' => $contentType->maxMediaCount(), 'current' => $count]);

                continue;
            }

            if ($count > 0) {
                $errors = [...$errors, ...ContentTypeCompatibleWithMedia::errorsFor(
                    [['key' => $key, 'content_type' => $contentType->value]],
                    self::withStoredFiles($reply['media'], $workspace),
                    $workspace,
                )];
            }
        }

        return $errors;
    }

    /**
     * The REST API and MCP send a reply's media as references (`{upload_token}`
     * or `{id}`), so the item says nothing about the file. Each reference is
     * read from its row in the workspace, the way the web composer sends it,
     * so the reply is measured against the file it points to.
     *
     * @param  list<array<string, mixed>>  $media
     * @return list<array<string, mixed>>
     */
    private static function withStoredFiles(array $media, ?Workspace $workspace): array
    {
        $references = collect($media)->filter(fn (array $item): bool => blank(data_get($item, 'type')) && blank(data_get($item, 'mime_type')));

        if ($workspace === null || $references->isEmpty()) {
            return $media;
        }

        $byToken = ResolveWorkspaceMedia::byUploadTokens($workspace, $references->pluck('upload_token')->filter(fn (mixed $token): bool => is_string($token) && $token !== '')->values()->all());
        $byId = ResolveWorkspaceMedia::execute($workspace, $references->pluck('id')->filter(fn (mixed $id): bool => is_string($id) && Str::isUuid($id))->values()->all());

        return array_map(function (array $item) use ($byToken, $byId): array {
            if (filled(data_get($item, 'type')) || filled(data_get($item, 'mime_type'))) {
                return $item;
            }

            $row = filled(data_get($item, 'upload_token'))
                ? $byToken->get(data_get($item, 'upload_token'))
                : $byId->get(data_get($item, 'id'));

            return $row === null ? $item : [...MediaItem::fromMedia($row)->toArray(), ...$item];
        }, $media);
    }
}
