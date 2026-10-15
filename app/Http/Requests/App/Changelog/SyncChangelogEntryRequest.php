<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Changelog;

use App\Enums\Changelog\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * One "What's New" entry folder as `changelog:sync` reads it: the folder name
 * (`slug`), its `cover.png` and the front matter and body of its `entry.md`.
 */
class SyncChangelogEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'slug' => ['alpha_dash:ascii', 'lowercase'],
            'cover' => [Rule::dimensions()->ratio(16 / 9)],
            'title' => ['required', 'string', 'max:255'],
            'tags' => ['list'],
            'tags.*' => ['required', 'distinct', Rule::enum(Tag::class)],
            'date' => ['required', 'date_format:Y-m-d', Rule::in([Str::substr((string) $this->input('slug'), 0, 10)])],
            'body' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cover.dimensions' => 'The cover.png must be 16:9 (1200x675).',
            'date.in' => 'The folder name must start with the date (<YYYY-MM-DD>-<slug>).',
        ];
    }
}
