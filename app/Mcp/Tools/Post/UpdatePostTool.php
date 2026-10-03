<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\HostInlineMedia;
use App\Actions\Post\UpdatePost;
use App\Enums\Post\Action as PostAction;
use App\Enums\Post\QueuePosition;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\DescribesPostMedia;
use App\Models\Post;
use App\Models\Workspace;
use App\Rules\ContentTypeCompatibleWithMedia;
use App\Rules\PostContentFitsMaxLength;
use App\Support\PostMediaRules;
use App\Support\PostPlatformMetaRules;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Update one post for its existing social account. Caption, content type, platform settings, schedule, and labels may change. The social account is fixed.')]
class UpdatePostTool extends Tool
{
    use AuthorizesMcpTool;
    use DescribesPostMedia;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $request->user()?->currentWorkspace;
        $post = $workspace instanceof Workspace
            ? Post::where('workspace_id', $workspace->id)->find(data_get($request->all(), 'post_id'))
            : null;

        if (! $post) {
            return Response::error('Post not found.');
        }

        if ($denied = $this->denyUnlessCan($request, 'update', $post, 'Not authorized to update this post.')) {
            return $denied;
        }

        $status = data_get($request->all(), 'status');

        $validated = $request->validate(
            [
                'post_id' => ['required', 'uuid'],
                'content' => ['nullable', 'string', new PostContentFitsMaxLength],
                ...PostMediaRules::rules(),
                'scheduled_at' => PostStatusRules::scheduledAtRules($post, $status, filled(data_get($request->all(), 'queue'))),
                'queue' => PostStatusRules::queueRules(),
                'status' => ['sometimes', 'string', Rule::in([Status::Draft->value, Status::Scheduled->value])],
                'label_ids' => ['sometimes', 'array'],
                'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)->withoutTrashed()],
                'social_account_id' => ['prohibited'],
                'platforms' => ['prohibited'],
                'content_type' => ['sometimes', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
                'meta' => ['sometimes', 'array'],
            ],
            [...PostPlatformMetaRules::messages(), ...PostStatusRules::queueMessages()],
            PostPlatformMetaRules::attributes(),
        );

        if (array_key_exists('media', $validated)) {
            $validated['media'] = HostInlineMedia::execute($workspace, $post->allowedMediaTypes(), $validated['media']);
        }

        // Without new media, scheduling validates the stored media against the
        // effective type even when the request omits content_type.
        if ($status === Status::Scheduled->value && ! array_key_exists('media', $validated)) {
            $selectedTarget = $post->postPlatforms()->enabled()->first();
            $submittedTarget = $selectedTarget && isset($validated['content_type'])
                ? [['id' => $selectedTarget->id, 'content_type' => $validated['content_type']]]
                : null;
            $errors = ContentTypeCompatibleWithMedia::errorsFor(
                ContentTypeCompatibleWithMedia::entriesForUpdate($post, $submittedTarget, data_get($validated, 'meta')),
                (array) ($post->media ?? []),
                $post->workspace,
                array_key_exists('content', $validated) ? $validated['content'] : $post->content,
            );

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }
        }

        $payload = collect($validated)->except('post_id')->all();

        try {
            $result = UpdatePost::execute($workspace, $post, $payload, $request->user());
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        if (data_get($result, 'action') === PostAction::Finalized) {
            return Response::error(PostStatusRules::editBlockedMessage());
        }

        /** @var Post $updated */
        $updated = data_get($result, 'post');
        $updated->load(['postPlatforms.socialAccount', 'labels']);

        return Response::structured((new PostResource($updated))->resolve());
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'post_id' => $schema->string()->required()->description('UUID of the post to update.'),
            'content' => $schema->string()->description('New caption/text body.'),
            'media' => $this->mediaSchema($schema, 'Replaces the post media; omit to keep the current media.'),
            'scheduled_at' => $schema->string()->description('Future ISO 8601 datetime. Required for status "scheduled" unless the post already has a future schedule.'),
            'queue' => $schema->string()->enum(array_column(QueuePosition::cases(), 'value'))->description(PostStatusRules::QUEUE_DESCRIPTION),
            'status' => $schema->string()
                ->enum([Status::Draft->value, Status::Scheduled->value])
                ->description('Post status. Use "draft" to keep editing, "scheduled" to schedule the post. Use publish-post-tool for immediate publish.'),
            'label_ids' => $schema->array()
                ->items($schema->string())
                ->description('Workspace label IDs to attach (replaces existing labels).'),
            'content_type' => $schema->string()->description('New format for the post’s existing social account.'),
            'meta' => $schema->object()->description('Settings for the existing account, merged with stored settings. '.PostPlatformMetaRules::documentation()),
        ];
    }
}
