<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Asset;

use App\Enums\Media\Type as MediaType;
use App\Models\Media;
use App\Models\Workspace;
use App\Services\Media\AssetUsageQuery;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List media from the current workspace Asset Library with safe metadata and usage information.')]
class ListAssetsTool extends Tool
{
    public function handle(Request $request, AssetUsageQuery $usageQuery): Response|ResponseFactory
    {
        $validated = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'string', 'max:255'],
            'mime_type' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', Rule::in(['image', 'video', 'document'])],
            'usage' => ['sometimes', 'string', Rule::in(['all', 'used', 'unused'])],
            'sort' => ['sometimes', 'string', Rule::in([
                'created_at',
                'last_used_at',
                'usage_count',
                'publication_usage_count',
                'timestamped_publication_usage_count',
            ])],
            'direction' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],
        ]);

        $page = (int) data_get($validated, 'page', 1);
        $perPage = (int) data_get($validated, 'per_page', 25);
        $sort = (string) data_get($validated, 'sort', 'created_at');
        $direction = (string) data_get($validated, 'direction', 'desc');
        $workspaceId = $request->user()->current_workspace_id;

        $query = Media::query()
            ->where('mediable_type', (new Workspace)->getMorphClass())
            ->where('mediable_id', $workspaceId)
            ->where('collection', 'assets');

        if ($search = data_get($validated, 'search')) {
            $query->where('original_filename', 'like', '%'.trim((string) $search).'%');
        }

        if ($mimeType = data_get($validated, 'mime_type')) {
            $mimeType = trim((string) $mimeType);
            str_ends_with($mimeType, '/*')
                ? $query->where('mime_type', 'like', substr($mimeType, 0, -1).'%')
                : $query->where('mime_type', $mimeType);
        }

        if ($category = data_get($validated, 'category')) {
            $query->where('type', $category);
        }

        if (! $this->requiresUsageWideProjection((string) data_get($validated, 'usage', 'all'), $sort)) {
            $total = (clone $query)->count();
            $assets = $query
                ->orderBy('created_at', $direction)
                ->orderBy('id')
                ->forPage($page, $perPage)
                ->get();
            $usage = $usageQuery->forAssets(
                $request->user()->currentWorkspace,
                $assets->pluck('id')->all(),
                CarbonImmutable::now('UTC'),
            );

            return Response::structured([
                'assets' => $assets
                    ->map(fn (Media $media) => $this->assetPayload($media, $usage[$media->id] ?? []))
                    ->values()
                    ->all(),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => $total,
                    'has_more' => $page * $perPage < $total,
                ],
            ]);
        }

        $assets = $query->get();
        $usage = $usageQuery->forAssets(
            $request->user()->currentWorkspace,
            $assets->pluck('id')->all(),
            CarbonImmutable::now('UTC'),
        );

        $items = $assets
            ->map(fn (Media $media) => $this->assetPayload($media, $usage[$media->id] ?? []));

        $items = $this->filterByUsage($items, (string) data_get($validated, 'usage', 'all'));
        $items = $this->sortItems($items, $sort, $direction);

        $total = $items->count();
        $pageItems = $items->forPage($page, $perPage)->values();

        return Response::structured([
            'assets' => $pageItems->all(),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'has_more' => $page * $perPage < $total,
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page' => $schema->integer()->description('Page number, minimum 1. Default: 1.'),
            'per_page' => $schema->integer()->description('Items per page, 1-100. Default: 25.'),
            'search' => $schema->string()->description('Filename substring search, maximum 255 characters.'),
            'mime_type' => $schema->string()->description('Exact MIME type or safe prefix such as image/*.'),
            'category' => $schema->string()->enum(['image', 'video', 'document'])->description('Media category.'),
            'usage' => $schema->string()->enum(['all', 'used', 'unused'])->description('Filter by current content usage.'),
            'sort' => $schema->string()->enum([
                'created_at',
                'last_used_at',
                'usage_count',
                'publication_usage_count',
                'timestamped_publication_usage_count',
            ])->description('Sort field.'),
            'direction' => $schema->string()->enum(['asc', 'desc'])->description('Sort direction.'),
        ];
    }

    private function requiresUsageWideProjection(string $usage, string $sort): bool
    {
        return $usage !== 'all' || in_array($sort, [
            'last_used_at',
            'usage_count',
            'publication_usage_count',
            'timestamped_publication_usage_count',
        ], true);
    }

    /**
     * @param  array<string, mixed>  $usage
     * @return array<string, mixed>
     */
    private function assetPayload(Media $media, array $usage): array
    {
        return array_merge([
            'asset_id' => $media->id,
            'filename' => $media->original_filename,
            'mime_type' => $media->mime_type,
            'category' => $media->type instanceof MediaType ? $media->type->value : (string) $media->type,
            'size_bytes' => $media->size,
            'width' => data_get($media->meta, 'width'),
            'height' => data_get($media->meta, 'height'),
            'duration_seconds' => is_numeric(data_get($media->meta, 'duration')) ? (int) data_get($media->meta, 'duration') : null,
            'created_at' => $media->created_at?->utc()->toAtomString(),
            'preview_available' => filled($media->path),
        ], $usage);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function filterByUsage(Collection $items, string $usage): Collection
    {
        return match ($usage) {
            'used' => $items->filter(fn (array $item) => $item['is_used']),
            'unused' => $items->filter(fn (array $item) => ! $item['is_used']),
            default => $items,
        };
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function sortItems(Collection $items, string $sort, string $direction): Collection
    {
        return $items
            ->sortBy([
                fn (array $left, array $right): int => $this->compareNullable($left[$sort] ?? null, $right[$sort] ?? null, $direction),
                fn (array $left, array $right): int => strcmp((string) $left['asset_id'], (string) $right['asset_id']),
            ])
            ->values();
    }

    private function compareNullable(mixed $left, mixed $right, string $direction): int
    {
        if ($left === null && $right === null) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        $result = $left <=> $right;

        return $direction === 'desc' ? -$result : $result;
    }
}
