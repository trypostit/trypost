<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Analytics;

use App\Actions\Analytics\BuildWorkspaceAnalyticsReport;
use App\Actions\Analytics\ResolveAnalyticsChannelFilter;
use App\Actions\Analytics\ResolveAnalyticsRangePreset;
use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
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
#[Description('Get the current workspace analytics report for a date range (range preset 7d, 30d, mtd or custom with start and end; defaults to the last 30 days in your time zone), optionally narrowed to some channels (unknown channel ids are ignored, like the web Insights page), including summary, follower and post charts, top posts, per-account performance, data bounds, and sync coverage.')]
class GetAnalyticsReportTool extends Tool
{
    use AuthorizesMcpTool;

    public function __construct(
        private readonly BuildWorkspaceAnalyticsReport $analytics,
        private readonly ResolveAnalyticsRangePreset $presets,
        private readonly ResolveAnalyticsChannelFilter $channelFilter,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $presence = $request->get('range') === 'custom' ? ['required'] : ['sometimes', 'required'];
        $exclude = Rule::excludeIf(ResolveAnalyticsRangePreset::ignoresDates($request->get('range')));
        $validated = $request->validate([
            'range' => ['sometimes', Rule::in(ResolveAnalyticsRangePreset::PRESETS)],
            'start' => [$exclude, ...$presence, 'date_format:Y-m-d'],
            'end' => [$exclude, ...$presence, 'date_format:Y-m-d', 'after_or_equal:start'],
        ]);
        $user = $request->user();

        ['selection' => $selection, 'clamped' => $clamped] = $this->presets->selection($validated, $user->timezone);
        ['keys' => $channelKeys] = $this->channelFilter->execute($workspace, collect($request->get('channels', [])));

        return Response::structured($this->analytics->forSelection($workspace, $selection, $channelKeys, $clamped, weekStart: $user->week_starts_on));
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'range' => $schema->string()->enum(ResolveAnalyticsRangePreset::PRESETS)->description('Optional range preset: 7d, 30d, mtd or custom. Defaults to 30d; custom requires start and end, other presets ignore them.'),
            'start' => $schema->string()->description('Optional start date in YYYY-MM-DD format.'),
            'end' => $schema->string()->description('Optional end date in YYYY-MM-DD format.'),
            'channels' => $schema->array()->items($schema->string())->description('Optional social account UUIDs to report on; omitted or empty reports every channel included in analytics.'),
        ];
    }
}
