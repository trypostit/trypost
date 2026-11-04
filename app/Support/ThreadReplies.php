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
            Platform::LinkedIn, Platform::LinkedInPage => ContentType::LinkedInComment,
            default => null,
        };
    }

    public static function supports(?Platform $platform): bool
    {
        return self::replyContentType($platform) !== null;
    }

    public static function maxReplies(?Platform $platform): int
    {
        return match ($platform) {
            Platform::Bluesky, Platform::Mastodon, Platform::X => self::MAX_REPLIES,
            Platform::LinkedIn, Platform::LinkedInPage => 1,
            default => 0,
        };
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

        if (count($replies) > self::maxReplies($platform)) {
            return ['thread_replies', trans_choice('posts.form.thread.too_many', self::maxReplies($platform), ['max' => self::maxReplies($platform)])];
        }

        $reserved = $platform->reservedLength(is_array($meta) ? $meta : null);
        $account = $target instanceof SocialAccount ? $target : null;

        foreach ($replies as $index => $reply) {
            $violation = self::replyViolation($reply, $platform, $account, $reserved);

            if (filled($violation)) {
                [$field, $message] = $violation;

                return [self::replyKey($index, $field), $message];
            }
        }

        return null;
    }

    /**
     * The validation key of a reply (`thread_replies.0`), or of one of its
     * fields (`thread_replies.0.poll.options`).
     */
    private static function replyKey(int $index, ?string $field): string
    {
        if (blank($field)) {
            return "thread_replies.{$index}";
        }

        return "thread_replies.{$index}.{$field}";
    }

    /**
     * Why one reply cannot go: a poll is checked as a poll, any other reply
     * needs text or media and must fit the account's limit. The field is
     * relative to the reply (null for the reply itself).
     *
     * @param  array{text: string, media: list<array<string, mixed>>, poll?: array<string, mixed>}  $reply
     * @return array{0: string|null, 1: string}|null
     */
    private static function replyViolation(array $reply, Platform $platform, ?SocialAccount $account, int $reserved): ?array
    {
        if ($platform === Platform::X && self::hasPoll($reply)) {
            return self::pollReplyViolation($reply, $account, $reserved);
        }

        if (filled(Str::trim($reply['text']))) {
            return self::lengthViolation($reply['text'], $platform, $account, self::replyContentType($platform), $reserved);
        }

        if (blank($reply['media'])) {
            return [null, __('posts.form.thread.reply_empty')];
        }

        return null;
    }

    /**
     * A reply poll needs its question (the reply's text) within the poll's cap,
     * and choices and a length X accepts.
     *
     * @param  array{text: string, media: list<array<string, mixed>>, poll?: array<string, mixed>}  $reply
     * @return array{0: string|null, 1: string}|null
     */
    private static function pollReplyViolation(array $reply, ?SocialAccount $account, int $reserved): ?array
    {
        $questionViolation = XPoll::questionViolation($reply['text']);

        if (filled($questionViolation)) {
            return [null, $questionViolation];
        }

        return self::lengthViolation($reply['text'], Platform::X, $account, ContentType::XPoll, $reserved)
            ?? XPoll::violation($reply)
            ?? XPoll::requiredViolation($reply);
    }

    /**
     * A reply's text over its limit, measured as the network counts it.
     *
     * @return array{0: null, 1: string}|null
     */
    private static function lengthViolation(string $text, Platform $platform, ?SocialAccount $account, ?ContentType $type, int $reserved): ?array
    {
        $display = app(ContentSanitizer::class)->displayText($text, $platform);
        $over = ContentType::overflowFor($account, $platform, $type, $display, $reserved);

        if ($over === 0) {
            return null;
        }

        $limit = ContentType::maxContentLengthFor($account, $platform, $type) - $reserved;

        if ($type === ContentType::LinkedInComment) {
            return [null, __('posts.form.thread.comment_too_long', ['limit' => $limit, 'over' => $over])];
        }

        return [null, __('posts.form.thread.reply_too_long', ['limit' => $limit, 'over' => $over])];
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
