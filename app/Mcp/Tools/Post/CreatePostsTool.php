<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\CreatePosts;
use App\Actions\Post\HostInlineMedia;
use App\Enums\Post\CreatedVia;
use App\Enums\Post\QueuePosition;
use App\Enums\PostPlatform\ContentType;
use App\Exceptions\Post\QueueBusyException;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Mcp\Concerns\DescribesPostMedia;
use App\Models\Post;
use App\Models\Workspace;
use App\Rules\PostContentFitsMaxLength;
use App\Support\PostMediaRules;
use App\Support\PostStatusRules;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create one independent post per destination in the current workspace. Each destination may override the shared caption and hosted media.')]
class CreatePostsTool extends Tool
{
    use AuthorizesMcpTool;
    use DescribesPostMedia;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace(
            $request,
            'createPost',
            'Not authorized to create posts.',
        );

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['draft', 'scheduled', 'publishing'])],
            'content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules(),
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
            'queue' => PostStatusRules::queueRules(),
            'label_ids' => ['sometimes', 'array'],
            'label_ids.*' => ['uuid', Rule::exists('workspace_labels', 'id')->where('workspace_id', $workspace->id)->withoutTrashed()],
            'destinations' => ['required', 'array', 'min:1'],
            'destinations.*.social_account_id' => [
                'required',
                'uuid',
                Rule::exists('social_accounts', 'id')->where('workspace_id', $workspace->id),
            ],
            'destinations.*.content_type' => ['required', 'string', Rule::in(array_column(ContentType::cases(), 'value'))],
            'destinations.*.content' => ['sometimes', 'nullable', 'string', new PostContentFitsMaxLength],
            ...PostMediaRules::rules('destinations.*.media'),
            'destinations.*.meta' => ['sometimes', 'array'],
        ], PostStatusRules::queueMessages());

        $validated = HostInlineMedia::forBatch($workspace, $validated);

        $validated['created_via'] = CreatedVia::Mcp;

        try {
            $posts = CreatePosts::execute($workspace, $request->user(), $validated);
        } catch (QueueBusyException) {
            return Response::error(__('posts.errors.queue_busy'));
        }

        return Response::structured([
            'posts' => $posts->map(function (Post $post): array {
                $post->load(['postPlatforms.socialAccount', 'labels']);

                return (new PostResource($post))->resolve();
            })->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()->enum(['draft', 'scheduled', 'publishing'])->required(),
            'content' => $schema->string()->description('Shared caption; each destination may override it.'),
            'media' => $this->mediaSchema($schema, 'Media shared by every destination by default.'),
            'scheduled_at' => $schema->string()->description('Future ISO 8601 datetime, required when status is scheduled.'),
            'queue' => $schema->string()->enum(array_column(QueuePosition::cases(), 'value'))->description(PostStatusRules::QUEUE_DESCRIPTION),
            'label_ids' => $schema->array()->items($schema->string()),
            'destinations' => $schema->array()
                ->items($schema->object(fn ($destination) => [
                    'social_account_id' => $destination->string()->required(),
                    'content_type' => $destination->string()->required(),
                    'content' => $destination->string()->description('Caption override.'),
                    'media' => $this->mediaSchema($schema, 'Media override for this destination; same item shape as the shared media.'),
                    'meta' => $destination->object()->description('Platform settings.'),
                ]))
                ->required(),
        ];
    }
}
