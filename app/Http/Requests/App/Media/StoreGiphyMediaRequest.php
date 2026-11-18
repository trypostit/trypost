<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use App\Enums\Media\Source;
use App\Services\Media\RemoteMediaImporter;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Uri;

/**
 * One GIF picked in the composer's GIPHY picker: the file on the configured
 * GIPHY download hosts, and the GIF's id and its page on the GIPHY website
 * kept on the item.
 */
class StoreGiphyMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createPost', $this->user()->currentWorkspace);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'url' => ['bail', 'required', 'string', 'url:https', $this->onHosts(Source::Giphy->downloadHosts())],
            'gif_id' => ['required', 'string', 'alpha_num', 'max:64'],
            'giphy_url' => ['bail', 'nullable', 'string', 'url:https', 'max:2048', $this->onHosts([$this->websiteHost()])],
        ];
    }

    public function filename(): string
    {
        $id = $this->validated('gif_id');

        return "giphy-{$id}.gif";
    }

    /**
     * @return array{gif_id: string, giphy_url: ?string}
     */
    public function attribution(): array
    {
        return [
            'gif_id' => (string) $this->validated('gif_id'),
            'giphy_url' => $this->validated('giphy_url'),
        ];
    }

    private function websiteHost(): string
    {
        return Uri::of((string) config('trypost.media_sources.giphy.website'))->host();
    }

    /**
     * @param  list<string>  $hosts
     */
    private function onHosts(array $hosts): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hosts): void {
            if (! Source::Giphy->isEnabled() || ! app(RemoteMediaImporter::class)->hostAllowed((string) $value, $hosts)) {
                $fail(__('validation.url', ['attribute' => $attribute]));
            }
        };
    }
}
