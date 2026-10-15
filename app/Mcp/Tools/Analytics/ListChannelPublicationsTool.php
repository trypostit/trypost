<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Actions\Analytics\BuildChannelInsights;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Dto\Analytics\PublicationFilter;
use App\Http\Resources\Api\ChannelPublicationPerformanceResource;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Models\WorkspaceLabel;
use App\Support\Analytics\ChannelMetrics;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('List the best posts of one channel in a date range, ranked by a metric (sort; defaults to the first metric the channel reports), for the current range or the previous range of the same length (period), optionally narrowed by labels, untagged posts and post types. Range preset 7d, 30d, mtd or custom with start and end; defaults to the last 30 days in your time zone. Same order as the channel Insights page; the ranking stops at the top 50 posts. Paginated: pass page to read the next page.')]
class ListChannelPublicationsTool extends Tool
{
    use AuthorizesMcpTool;

    public function __construct(private readonly BuildChannelInsights $insights) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $request->validate(['account_id' => ['required', 'string', 'uuid']]);
        $account = SocialAccount::where('workspace_id', $workspace->id)->find($request->get('account_id'));

        if (! $account instanceof SocialAccount) {
            return Response::error('Social account not found.');
        }

        if (! $account->platform->isIncludedInAnalytics()) {
            return Response::error('Insights are not available for this channel.');
        }

        $exclude = Rule::excludeIf(ResolveAnalyticsRangePreset::ignoresDates($request->get('range')));
        $validated = $request->validate([
            'range' => ['sometimes', Rule::in(ResolveAnalyticsRangePreset::PRESETS)],
            'start' => [$exclude, 'required_if:range,custom', 'date_format:Y-m-d'],
            'end' => [$exclude, 'required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:start'],
            'period' => ['sometimes', Rule::in(['current', 'previous'])],
            'sort' => ['sometimes', Rule::in(ChannelMetrics::sortable())],
            'labels' => ['sometimes', 'array'],
            'labels.*' => ['bail', 'uuid', Rule::exists(WorkspaceLabel::class, 'id')
                ->where('workspace_id', $workspace->id)
                ->withoutTrashed()],
            'untagged' => ['sometimes', 'boolean'],
            'types' => ['sometimes', 'array'],
            'types.*' => [Rule::in(PublicationFilter::typesFor($account->platform))],
            'page' => ['sometimes', 'integer', 'min:1'],
        ]);

        ['filters' => $filters, 'publications' => $publications] = $this->insights->publications($account, $request->user(), $validated, (int) data_get($validated, 'page', 1));

        return Response::structured([
            'publications' => ChannelPublicationPerformanceResource::collection($publications->items())->resolve(),
            'filters' => $filters,
            'total' => $publications->total(),
            'per_page' => $publications->perPage(),
            'current_page' => $publications->currentPage(),
            'last_page' => $publications->lastPage(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'account_id' => $schema->string()->required()->description('The UUID of the connected social account.'),
            'range' => $schema->string()->enum(ResolveAnalyticsRangePreset::PRESETS)->description('Optional range preset: 7d, 30d, mtd or custom. Defaults to 30d; custom requires start and end, other presets ignore them.'),
            'start' => $schema->string()->description('Optional start date in YYYY-MM-DD format.'),
            'end' => $schema->string()->description('Optional end date in YYYY-MM-DD format.'),
            'period' => $schema->string()->enum(['current', 'previous'])->description('Optional: rank the posts of the current range (default) or of the previous range of the same length.'),
            'sort' => $schema->string()->enum(ChannelMetrics::sortable())->description('Optional metric to rank by; a metric the channel does not report falls back to the first one it does (see filters.sort).'),
            'labels' => $schema->array()->items($schema->string())->description('Optional workspace label UUIDs; only posts with one of them count.'),
            'untagged' => $schema->boolean()->description('Optional: include posts without labels (and posts published outside TryPost).'),
            'types' => $schema->array()->items($schema->string())->description('Optional post types of this channel\'s platform (see list-content-types-tool); only those posts count.'),
            'page' => $schema->integer()->description('Page number.'),
        ];
    }
}
