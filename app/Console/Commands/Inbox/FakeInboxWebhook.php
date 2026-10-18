<?php

declare(strict_types=1);

namespace App\Console\Commands\Inbox;

use App\Enums\Inbox\ConversationKind;
use App\Enums\SocialAccount\Platform;
use App\Models\InboxMessage;
use App\Models\Post;
use App\Models\SocialAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Sends a Meta webhook delivery for a connected channel, shaped like the
 * payloads Meta documents, to test the inbox locally without Meta.
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/reference/page/
 * @see https://developers.facebook.com/docs/graph-api/webhooks/reference/instagram
 * @see https://developers.facebook.com/docs/threads/webhooks
 */
class FakeInboxWebhook extends Command
{
    protected $signature = 'inbox:fake-webhook
        {network : instagram, facebook or threads}
        {event : comment, reply, edit, delete, live_comment, mention, message or echo}
        {--channel= : The channel (social account) id; the first connected one of the network by default}
        {--post= : The network id of the post a comment is under; the channel\'s newest post by default}
        {--target= : The comment a reply, edit or delete is about; the newest stored comment by default}
        {--text= : The message text}';

    protected $description = 'Send a signed Meta webhook delivery for a connected channel to this app (local and testing only)';

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->components->error('inbox:fake-webhook never runs in production.');

            return self::FAILURE;
        }

        $network = (string) $this->argument('network');
        $event = (string) $this->argument('event');

        if (! in_array($event, data_get(self::EVENTS, $network, []), true)) {
            $this->components->error("Unknown network or event. Supported: {$this->supported()}");

            return self::FAILURE;
        }

        $account = SocialAccount::query()
            ->connected()
            ->whereIn('platform', data_get(self::PLATFORMS, $network))
            ->when($this->option('channel'), fn (Builder $query, string $id) => $query->whereKey($id))
            ->first();

        if (! $account) {
            $this->components->error("No connected {$network} channel.");

            return self::FAILURE;
        }

        $secret = self::secretFor($account);

        if ($secret === '') {
            $this->components->error('The app secret Meta signs this channel\'s deliveries with is not set.');

            return self::FAILURE;
        }

        try {
            $payload = self::payload($account, $event, [
                'post' => $this->option('post') ?: $this->newestPostId($account),
                'target' => $this->option('target'),
                'text' => $this->option('text'),
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $body = (string) json_encode($payload);
        $response = Http::withHeaders(['X-Hub-Signature-256' => self::signature($body, $secret)])
            ->withBody($body, 'application/json')
            ->post(route('webhooks.meta'));

        if ($response->failed()) {
            $this->components->error("The webhook route answered HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $this->components->info("Sent a {$network} {$event} for {$account->platform->label()} channel {$account->id}.");
        $this->line($body);

        return self::SUCCESS;
    }

    private function newestPostId(SocialAccount $account): ?string
    {
        return Post::query()
            ->where('social_account_id', $account->id)
            ->whereNotNull('platform_post_id')
            ->latest('published_at')
            ->value('platform_post_id');
    }

    private function supported(): string
    {
        return collect(self::EVENTS)
            ->map(fn (array $events, string $network): string => "{$network} (".implode(', ', $events).')')
            ->implode('; ');
    }

    /** @var array<string, list<string>> */
    private const array EVENTS = [
        'facebook' => ['comment', 'reply', 'edit', 'delete', 'mention', 'message', 'echo'],
        'instagram' => ['comment', 'reply', 'live_comment', 'mention', 'message'],
        'threads' => ['comment', 'reply', 'delete', 'mention'],
    ];

    /** @var array<string, list<Platform>> */
    private const array PLATFORMS = [
        'facebook' => [Platform::Facebook],
        'instagram' => [Platform::Instagram, Platform::InstagramFacebook],
        'threads' => [Platform::Threads],
    ];

    /**
     * @param  array{post?: string|null, target?: string|null, text?: string|null}  $options
     * @return array<string, mixed>
     */
    private static function payload(SocialAccount $account, string $event, array $options = []): array
    {
        $text = data_get($options, 'text') ?: 'Test message from inbox:fake-webhook';
        $time = now()->getTimestamp();

        return match ($account->platform) {
            Platform::Facebook => self::facebook($account, $event, $options, $text, $time),
            Platform::Instagram, Platform::InstagramFacebook => self::instagram($account, $event, $options, $text, $time),
            Platform::Threads => self::threads($account, $event, $options, $text, $time),
            default => throw new InvalidArgumentException("{$account->platform->value} sends no Meta webhooks."),
        };
    }

    /**
     * The App Secret Meta signs this channel's deliveries with.
     */
    private static function secretFor(SocialAccount $account): string
    {
        return (string) match ($account->platform) {
            Platform::Instagram => config('services.instagram.client_secret'),
            Platform::Threads => config('services.threads.client_secret'),
            default => config('services.facebook.client_secret'),
        };
    }

    private static function signature(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function facebook(SocialAccount $account, string $event, array $options, string $text, int $time): array
    {
        $pageId = (string) $account->platform_user_id;
        $user = ['id' => self::numericId(), 'name' => 'Inbox Tester'];

        if (in_array($event, ['message', 'echo'], true)) {
            $person = ['id' => self::numericId()];
            $page = ['id' => $pageId];

            return ['object' => 'page', 'entry' => [[
                'id' => $pageId,
                'time' => $time,
                'messaging' => [[
                    'sender' => $event === 'echo' ? $page : $person,
                    'recipient' => $event === 'echo' ? $person : $page,
                    'timestamp' => now()->getTimestampMs(),
                    'message' => array_filter(['mid' => 'm_'.self::numericId(), 'text' => $text, 'is_echo' => $event === 'echo' ? true : null]),
                ]],
            ]]];
        }

        $postId = $event === 'mention' ? '' : self::require($options, 'post');
        $value = match ($event) {
            'comment' => ['from' => $user, 'post_id' => $postId, 'comment_id' => self::commentId($postId), 'parent_id' => $postId, 'created_time' => $time, 'item' => 'comment', 'verb' => 'add', 'message' => $text],
            'reply' => ['from' => $user, 'post_id' => $postId, 'comment_id' => self::commentId($postId), 'parent_id' => self::target($account, $options), 'created_time' => $time, 'item' => 'comment', 'verb' => 'add', 'message' => $text],
            'edit' => ['from' => $user, 'post_id' => $postId, 'comment_id' => self::target($account, $options), 'parent_id' => $postId, 'created_time' => $time, 'item' => 'comment', 'verb' => 'edited', 'message' => $text],
            'delete' => ['from' => $user, 'post_id' => $postId, 'comment_id' => self::target($account, $options), 'parent_id' => $postId, 'created_time' => $time, 'item' => 'comment', 'verb' => 'remove'],
            'mention' => ['post_id' => self::numericId().'_'.self::numericId(), 'item' => 'post', 'verb' => 'add', 'created_time' => $time, 'message' => $text],
            default => throw new InvalidArgumentException("Facebook has no {$event} event."),
        };

        return ['object' => 'page', 'entry' => [[
            'id' => $pageId,
            'time' => $time,
            'changes' => [['field' => $event === 'mention' ? 'mention' : 'feed', 'value' => $value]],
        ]]];
    }

    /**
     * An Instagram Login channel has no `mentions` field: a mention arrives as
     * a comment on media the channel does not own.
     *
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function instagram(SocialAccount $account, string $event, array $options, string $text, int $time): array
    {
        $accountId = (string) $account->instagramAccountId();
        $from = ['id' => self::numericId(), 'username' => 'inbox.tester'];

        if ($event === 'message') {
            return ['object' => 'instagram', 'entry' => [[
                'id' => $accountId,
                'time' => $time,
                'messaging' => [[
                    'sender' => ['id' => self::numericId()],
                    'recipient' => ['id' => $accountId],
                    'timestamp' => now()->getTimestampMs(),
                    'message' => ['mid' => 'aWdf'.self::numericId(), 'text' => $text],
                ]],
            ]]];
        }

        [$field, $value] = match (true) {
            $event === 'mention' && $account->platform === Platform::InstagramFacebook => ['mentions', ['media_id' => self::numericId(), 'comment_id' => self::numericId()]],
            $event === 'mention' => ['comments', ['from' => $from, 'media' => ['id' => self::numericId(), 'media_product_type' => 'FEED'], 'id' => self::numericId(), 'text' => "@{$account->username} {$text}"]],
            $event === 'comment' => ['comments', ['from' => $from, 'media' => ['id' => self::require($options, 'post'), 'media_product_type' => 'FEED'], 'id' => self::numericId(), 'text' => $text]],
            $event === 'reply' => ['comments', ['from' => $from, 'media' => ['id' => self::require($options, 'post'), 'media_product_type' => 'FEED'], 'id' => self::numericId(), 'parent_id' => self::target($account, $options), 'text' => $text]],
            $event === 'live_comment' => ['live_comments', ['from' => $from, 'media' => ['id' => self::require($options, 'post'), 'media_product_type' => 'LIVE'], 'id' => self::numericId(), 'text' => $text]],
            default => throw new InvalidArgumentException("Instagram has no {$event} event."),
        };

        return ['object' => 'instagram', 'entry' => [[
            'id' => $accountId,
            'time' => $time,
            'changes' => [['field' => $field, 'value' => $value]],
        ]]];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private static function threads(SocialAccount $account, string $event, array $options, string $text, int $time): array
    {
        $id = self::numericId();
        $timestamp = now()->format('Y-m-d\TH:i:sO');

        $value = match ($event) {
            'comment', 'reply' => [
                'id' => $id,
                'username' => 'inbox.tester',
                'text' => $text,
                'media_type' => 'TEXT_POST',
                'permalink' => "https://www.threads.net/@inbox.tester/post/{$id}",
                'replied_to' => ['id' => $event === 'reply' ? self::target($account, $options) : self::require($options, 'post')],
                'root_post' => ['id' => self::require($options, 'post'), 'owner_id' => (string) $account->platform_user_id, 'username' => (string) $account->username],
                'shortcode' => $id,
                'timestamp' => $timestamp,
            ],
            'mention' => [
                'id' => $id,
                'username' => 'inbox.tester',
                'text' => "@{$account->username} {$text}",
                'media_type' => 'TEXT_POST',
                'media_product_type' => 'THREADS',
                'permalink' => "https://www.threads.net/@inbox.tester/post/{$id}",
                'shortcode' => $id,
                'timestamp' => $timestamp,
                'has_replies' => false,
                'is_quote_post' => false,
                'is_reply' => false,
            ],
            'delete' => [
                'id' => self::target($account, $options),
                'owner' => ['owner_id' => (string) $account->platform_user_id],
                'deleted_at' => $timestamp,
                'timestamp' => $timestamp,
                'username' => (string) $account->username,
            ],
            default => throw new InvalidArgumentException("Threads has no {$event} event."),
        };

        return [
            'app_id' => (string) config('services.threads.client_id'),
            'topic' => $event === 'mention' ? 'interaction' : 'moderate',
            'target_id' => $event === 'delete' ? (string) data_get($value, 'id') : (string) $account->platform_user_id,
            'time' => $time,
            'subscription_id' => self::numericId(),
            'has_uid_field' => false,
            'values' => ['value' => $value, 'field' => match ($event) {
                'mention' => 'mentions',
                'delete' => 'delete',
                default => 'replies',
            }],
        ];
    }

    /**
     * The comment a reply, an edit or a delete is about: the one given, else
     * the channel's newest stored comment.
     *
     * @param  array<string, mixed>  $options
     */
    private static function target(SocialAccount $account, array $options): string
    {
        $target = data_get($options, 'target') ?: InboxMessage::query()
            ->where('social_account_id', $account->id)
            ->whereNull('removed_at')
            ->whereHas('conversation', fn (Builder $conversation) => $conversation->where('kind', ConversationKind::Comment->value))
            ->latest('platform_created_at')
            ->value('platform_message_id');

        if (blank($target)) {
            throw new InvalidArgumentException('No stored comment to answer, edit or delete: send a comment first or pass --target.');
        }

        return (string) $target;
    }

    /** @param array<string, mixed> $options */
    private static function require(array $options, string $key): string
    {
        $value = data_get($options, $key);

        if (blank($value)) {
            throw new InvalidArgumentException("This event needs --{$key}.");
        }

        return (string) $value;
    }

    private static function commentId(string $postId): string
    {
        $postPart = str_contains($postId, '_') ? substr($postId, (int) strrpos($postId, '_') + 1) : $postId;

        return "{$postPart}_".self::numericId();
    }

    private static function numericId(): string
    {
        return (string) random_int(10_000_000_000_000, 99_999_999_999_999);
    }
}
