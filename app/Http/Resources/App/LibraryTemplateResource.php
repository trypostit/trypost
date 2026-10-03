<?php

declare(strict_types=1);

namespace App\Http\Resources\App;

use App\Dto\LibraryTemplate;
use App\Enums\PostTemplate\Audience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin LibraryTemplate
 */
class LibraryTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'key' => $this->key,
            'emoji' => $this->emoji,
            'type' => $this->type->value,
            'audiences' => array_map(fn (Audience $audience): string => $audience->value, $this->audiences),
            'format' => $this->format->value,
            'goal' => $this->goal->value,
            'featured' => $this->featured,
            'title' => $this->title(),
            'description' => $this->description(),
            'body' => $this->body(),
        ];
    }
}
