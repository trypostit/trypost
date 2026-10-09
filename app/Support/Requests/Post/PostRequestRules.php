<?php

declare(strict_types=1);

namespace App\Support\Requests\Post;

use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Rules\ContentFitsPlatformLimits;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\ContentTypeMatchesPlatform;
use App\Rules\ContentTypeMatchesPostChannel;
use App\Rules\PostContentFitsMaxLength;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Single source of the post create, batch, update and publish rules shared by
 * the public API FormRequests and the MCP post tools. Every input is explicit,
 * so the rules never depend on the current request or authenticated user.
 */
class PostRequestRules
{
    private const STATUSES = [Status::Draft->value, Status::Scheduled->value, Status::Publishing->value];

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function store(Workspace $workspace, array $input): array
    {
        $accounts = self::selectedAccounts($workspace, $input);

        return [
            'content' => [
                'nullable',
                'string',
                new PostContentFitsMaxLength,
                Rule::when(
                    in_array(data_get($input, 'status'), [Status::Scheduled->value, Status::Publishing->value], true),
                    [new ContentFitsPlatformLimits(
                        $accounts,
                        $accounts->map(fn (): array => (array) data_get($input, 'meta', []))->all(),
                        $accounts->map(fn (): mixed => data_get($input, 'content_type'))->all(),
                    )],
                ),
            ],
            ...PostMediaRules::rules(),
            'platforms' => ['prohibited'],
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id),
            ],
            'content_type' => [
                'sometimes',
                'nullable',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPlatform($workspace->id),
            ],
            ...PostPlatformMetaRules::rules(),
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
            'queue' => PostStatusRules::queueRules(),
            'queue_slot' => ['nullable', 'date', 'before:2038-01-19', 'prohibited_unless:status,scheduled', 'prohibits:queue'],
            ...self::labelRules($workspace),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function batch(Workspace $workspace): array
    {
        return [
            'status' => ['required', 'string', Rule::in(self::STATUSES)],
            'content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules(),
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
            'queue' => PostStatusRules::queueRules(),
            ...self::labelRules($workspace),
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id),
            ],
            'destinations.*.content_type' => ['sometimes', 'nullable', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'destinations.*.content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules('destinations.*.media'),
            'destinations.*.meta' => ['sometimes', 'array'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function update(Workspace $workspace, Post $post, array $input): array
    {
        $status = data_get($input, 'status');
        $target = $post->socialAccount ?? $post->platform;

        return [
            'status' => ['sometimes', 'string', Rule::in(self::STATUSES)],
            'content' => [
                'nullable',
                'string',
                new PostContentFitsMaxLength,
                Rule::when(
                    in_array($status, [Status::Scheduled->value, Status::Publishing->value], true),
                    [new ContentFitsPlatformLimits(
                        collect(filled($target) ? ['post' => $target] : []),
                        ['post' => self::effectiveMeta($post, $input)],
                        ['post' => data_get($input, 'content_type') ?? $post->content_type?->value],
                    )],
                ),
            ],
            ...PostMediaRules::rules(),
            'social_account_id' => ['prohibited'],
            'platforms' => ['prohibited'],
            'content_type' => [
                'sometimes',
                'string',
                Rule::in(array_column(ContentType::cases(), 'value')),
                new ContentTypeMatchesPostChannel($post),
            ],
            ...PostPlatformMetaRules::rules(),
            'scheduled_at' => PostStatusRules::scheduledAtRules($post, $status, filled(data_get($input, 'queue'))),
            'queue' => PostStatusRules::queueRules(),
            ...self::labelRules($workspace),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publish(): array
    {
        return [
            'post_id' => ['required', 'uuid'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19', 'prohibits:queue'],
            'queue' => ['nullable', Rule::enum(QueuePosition::class)],
        ];
    }

    /**
     * Required-on-publish meta and media compatibility checks of an update
     * that schedules or publishes, run after the field rules.
     *
     * @param  array<string, mixed>  $input
     */
    public static function afterUpdate(Validator $validator, Post $post, array $input): void
    {
        if (! in_array(data_get($input, 'status'), [Status::Scheduled->value, Status::Publishing->value], true)) {
            return;
        }

        self::addMediaCompatibilityErrors($validator, $post, $input);

        if (array_key_exists('meta', $input)) {
            PostPlatformMetaRules::addRequiredOnPublishErrors($validator, $post->socialAccount ?? $post->platform, self::effectiveMeta($post, $input));
        }
    }

    /**
     * The workspace account chosen in `social_account_id`, keyed by id.
     *
     * @param  array<string, mixed>  $input
     * @return Collection<string, SocialAccount>
     */
    public static function selectedAccounts(Workspace $workspace, array $input): Collection
    {
        $accountId = data_get($input, 'social_account_id');

        if (! is_string($accountId) || ! Str::isUuid($accountId)) {
            return collect();
        }

        return SocialAccount::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($accountId)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            ...PostPlatformMetaRules::messages(),
            ...PostStatusRules::queueMessages(),
            'scheduled_at.prohibits' => __('posts.errors.queue_with_scheduled_at'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(): array
    {
        return PostPlatformMetaRules::attributes();
    }

    /**
     * @return array<string, mixed>
     */
    private static function labelRules(Workspace $workspace): array
    {
        return [
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)->withoutTrashed()],
        ];
    }

    /**
     * Validates every submitted (or stored) content type against the
     * effective media: the request's media when sent, otherwise the stored one.
     *
     * @param  array<string, mixed>  $input
     */
    private static function addMediaCompatibilityErrors(Validator $validator, Post $post, array $input): void
    {
        if (filled(data_get($input, 'content_type'))) {
            return;
        }

        $media = array_key_exists('media', $input) ? (array) data_get($input, 'media', []) : (array) ($post->media ?? []);

        $entries = ContentTypeCompatibleWithMedia::entriesForUpdate(
            $post,
            null,
            array_key_exists('media', $input) ? $media : null,
        );

        foreach (ContentTypeCompatibleWithMedia::errorsFor($entries, $media, $post->workspace) as $key => $message) {
            $validator->errors()->add($key, $message);
        }
    }

    /**
     * The meta the update would store: the submitted keys over the stored ones.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private static function effectiveMeta(Post $post, array $input): array
    {
        return [...($post->meta ?? []), ...(array) data_get($input, 'meta', [])];
    }
}
