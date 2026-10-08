<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Post;

use App\Actions\Post\BuildPublishPageProps;
use App\Enums\Post\Status;
use App\Http\Resources\Api\PostResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Support\RequestIds;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List posts for the current workspace, ordered by scheduled date (newest first). Optional filters: status (draft|scheduled|pending_approval|published|failed), search (matches against post content), channels (social account IDs), labels (label IDs) with untagged (posts without labels), like the publish page filters. Unknown IDs match nothing. Paginated with the app page size: pass page; the response carries total, per_page, current_page and last_page.')]
class ListPostsTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', Rule::in([
                Status::Draft->value,
                Status::Scheduled->value,
                Status::Published->value,
                Status::Failed->value,
                Status::PendingApproval->value,
            ])],
            'search' => ['sometimes', 'string', 'max:255'],
            'channels' => ['sometimes', 'nullable', 'array'],
            'labels' => ['sometimes', 'nullable', 'array'],
            'untagged' => ['sometimes', 'nullable'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        $user = $request->user();

        $query = $workspace
            ->posts()
            ->visiblePendingApprovalsFor(BuildPublishPageProps::pendingApprovalsRequester($user, $workspace))
            ->onChannels(RequestIds::uuidList(collect((array) data_get($validated, 'channels'))->unique()) ?: null)
            ->matchingLabelFilter(
                RequestIds::uuidList(collect((array) data_get($validated, 'labels'))),
                filter_var(data_get($validated, 'untagged'), FILTER_VALIDATE_BOOLEAN),
            )
            ->with(['socialAccount', 'user', 'approvalRequestedBy', 'approver', 'labels']);

        $query = match (data_get($validated, 'status')) {
            Status::Draft->value => $query->draft(),
            Status::Scheduled->value => $query->scheduled(),
            Status::Published->value => $query->published(),
            Status::Failed->value => $query->failed(),
            Status::PendingApproval->value => $query->pendingApproval(),
            default => $query,
        };

        if ($search = data_get($validated, 'search')) {
            $query->whereLike('content', '%'.$search.'%');
        }

        $posts = $query->latestScheduledFirst()
            ->paginate((int) config('app.pagination.default'), page: (int) data_get($validated, 'page', 1));

        return Response::structured([
            'posts' => PostResource::collection($posts->items())->resolve(),
            'total' => $posts->total(),
            'per_page' => $posts->perPage(),
            'current_page' => $posts->currentPage(),
            'last_page' => $posts->lastPage(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->enum(['draft', 'scheduled', 'published', 'failed', 'pending_approval'])
                ->description('Filter by status. "published" includes partially-published posts.'),
            'search' => $schema->string()->description('Case-insensitive substring match against the post content.'),
            'channels' => $schema->array()->items($schema->string())->description('Only posts on one of these social account IDs (list-social-accounts-tool).'),
            'labels' => $schema->array()->items($schema->string())->description('Only posts with one of these label IDs.'),
            'untagged' => $schema->boolean()->description('Include posts without labels (combines with labels).'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
