<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Enums\Post\Status;
use App\Http\Resources\Api\PostResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List posts for the current workspace, ordered by scheduled date (newest first). Optional filters: status (draft|scheduled|published) and search (matches against post content).')]
class ListPostsTool extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                Status::Draft->value,
                Status::Scheduled->value,
                Status::Published->value,
                Status::Failed->value,
            ])],
            'search' => ['sometimes', 'string', 'max:255'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()->currentWorkspace
            ->posts()
            ->with(['postPlatforms.socialAccount', 'labels']);

        $query = match (data_get($validated, 'status')) {
            Status::Draft->value => $query->draft(),
            Status::Scheduled->value => $query->scheduled(),
            Status::Published->value => $query->published(),
            Status::Failed->value => $query->failed(),
            default => $query,
        };

        if ($search = data_get($validated, 'search')) {
            $query->whereLike('content', '%'.$search.'%');
        }

        $posts = $query->latest('scheduled_at')
            ->limit((int) data_get($validated, 'limit', 50))
            ->get();

        return Response::structured([
            'posts' => PostResource::collection($posts)->resolve(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['draft', 'scheduled', 'published', 'failed'])
                ->description('Filter by status. "published" includes partially-published posts.'),
            'search' => $schema->string()->description('Case-insensitive substring match against the post content.'),
            'limit' => $schema->integer()->description('Max results (1-100, default 50).'),
        ];
    }
}
