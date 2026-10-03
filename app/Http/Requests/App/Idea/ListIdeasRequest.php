<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Idea;

use App\Models\Idea;
use App\Support\RequestIds;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

class ListIdeasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Idea::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'view' => ['sometimes', 'nullable', 'string'],
            'stage' => ['sometimes', 'nullable', 'string'],
            'stages' => ['sometimes', 'nullable', 'array'],
            'labels' => ['sometimes', 'nullable', 'array'],
            'untagged' => ['sometimes', 'nullable'],
            'unassigned' => ['sometimes', 'nullable'],
        ];
    }

    public function isGallery(): bool
    {
        return $this->query('view') === 'gallery';
    }

    public function stageId(): ?string
    {
        $stage = $this->query('stage');

        return is_string($stage) && Str::isUuid($stage) ? $stage : null;
    }

    /**
     * @return list<string>
     */
    public function stageIds(): array
    {
        return $this->uuidList('stages');
    }

    /**
     * @return list<string>
     */
    public function labelIds(): array
    {
        return $this->uuidList('labels');
    }

    public function untagged(): bool
    {
        return $this->boolean('untagged');
    }

    public function unassigned(): bool
    {
        return $this->boolean('unassigned');
    }

    /**
     * @return list<string>
     */
    private function uuidList(string $key): array
    {
        return RequestIds::uuidList(collect((array) $this->query($key)));
    }
}
