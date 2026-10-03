<?php

declare(strict_types=1);

namespace App\Actions\PostTemplate;

use App\Enums\PostTemplate\Audience;
use App\Enums\PostTemplate\Format;
use App\Enums\PostTemplate\Goal;
use App\Enums\PostTemplate\Type;
use App\Http\Requests\App\PostTemplate\ListTemplatesRequest;
use App\Http\Resources\App\LibraryTemplateResource;
use App\Http\Resources\App\PostTemplateResource;
use App\Models\PostTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TemplateLibrary;
use BackedEnum;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;

class BuildTemplatesPageProps
{
    /**
     * @return array<string, mixed>
     */
    public static function execute(ListTemplatesRequest $request, Workspace $workspace, User $user): array
    {
        $view = $request->view();

        $props = [
            'view' => $view,
            'counts' => fn (): array => [
                'discover' => count(TemplateLibrary::all()),
                'team' => PostTemplate::query()->where('workspace_id', $workspace->id)->team()->count(),
                'personal' => PostTemplate::query()->where('workspace_id', $workspace->id)->personalFor($user)->count(),
            ],
            'filters' => [
                'search' => $request->search(),
                'types' => self::values($request->facet('types', Type::class)),
                'audiences' => self::values($request->facet('audiences', Audience::class)),
                'formats' => self::values($request->facet('formats', Format::class)),
                'goals' => self::values($request->facet('goals', Goal::class)),
            ],
            'facets' => [
                'types' => self::values(Type::cases()),
                'audiences' => self::values(Audience::cases()),
                'formats' => self::values(Format::cases()),
                'goals' => self::values(Goal::cases()),
            ],
        ];

        if ($view === 'discover') {
            $props['library'] = fn () => self::library($request);

            return $props;
        }

        $query = PostTemplate::query()
            ->where('workspace_id', $workspace->id)
            ->when($view === 'team', fn (Builder $builder) => $builder->team(), fn (Builder $builder) => $builder->personalFor($user))
            ->with('user:id,name')
            ->search($request->search())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $props['templates'] = Inertia::scroll(fn () => PostTemplateResource::collection(
            $query->paginate((int) config('app.pagination.default'))
        ));

        return $props;
    }

    /**
     * @return array<string, mixed>
     */
    private static function library(ListTemplatesRequest $request): array
    {
        if ($request->hasActiveFilters()) {
            return [
                'results' => LibraryTemplateResource::collection(TemplateLibrary::filter([
                    'search' => $request->search(),
                    'types' => $request->facet('types', Type::class),
                    'audiences' => $request->facet('audiences', Audience::class),
                    'formats' => $request->facet('formats', Format::class),
                    'goals' => $request->facet('goals', Goal::class),
                ]))->resolve(),
            ];
        }

        $all = TemplateLibrary::all();

        return [
            'featured' => LibraryTemplateResource::collection(TemplateLibrary::featured())->resolve(),
            'rows' => array_map(function (Type $type) use ($all): array {
                $templates = array_values(array_filter($all, fn ($template): bool => $template->type === $type));

                return [
                    'type' => $type->value,
                    'templates' => LibraryTemplateResource::collection(array_slice($templates, 0, 10))->resolve(),
                    'total' => count($templates),
                ];
            }, Type::cases()),
        ];
    }

    /**
     * @param  array<int, BackedEnum>  $cases
     * @return list<string|int>
     */
    private static function values(array $cases): array
    {
        return array_values(array_map(fn (BackedEnum $case): string|int => $case->value, $cases));
    }
}
