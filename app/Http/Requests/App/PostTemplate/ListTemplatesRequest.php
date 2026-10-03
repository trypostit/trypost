<?php

declare(strict_types=1);

namespace App\Http\Requests\App\PostTemplate;

use App\Enums\PostTemplate\Audience;
use App\Enums\PostTemplate\Format;
use App\Enums\PostTemplate\Goal;
use App\Enums\PostTemplate\Type;
use App\Models\PostTemplate;
use BackedEnum;
use Illuminate\Foundation\Http\FormRequest;

class ListTemplatesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', PostTemplate::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'nullable', 'string'],
            'search' => ['sometimes', 'nullable'],
            'types' => ['sometimes', 'nullable'],
            'audiences' => ['sometimes', 'nullable'],
            'formats' => ['sometimes', 'nullable'],
            'goals' => ['sometimes', 'nullable'],
        ];
    }

    public function view(): string
    {
        $view = $this->query('view');

        return in_array($view, ['team', 'personal'], true) ? $view : 'discover';
    }

    public function search(): ?string
    {
        $search = $this->query('search');

        if (! is_string($search)) {
            return null;
        }

        $search = trim(mb_substr($search, 0, 100));

        return $search === '' ? null : $search;
    }

    /**
     * @template T of BackedEnum
     *
     * @param  class-string<T>  $enumClass
     * @return list<T>
     */
    public function facet(string $key, string $enumClass): array
    {
        $values = [];

        foreach ((array) $this->query($key) as $value) {
            $case = (is_string($value) || is_int($value)) ? $enumClass::tryFrom($value) : null;

            if ($case !== null && ! in_array($case, $values, true)) {
                $values[] = $case;
            }
        }

        return $values;
    }

    public function hasActiveFilters(): bool
    {
        return $this->search() !== null
            || $this->facet('types', Type::class) !== []
            || $this->facet('audiences', Audience::class) !== []
            || $this->facet('formats', Format::class) !== []
            || $this->facet('goals', Goal::class) !== [];
    }
}
