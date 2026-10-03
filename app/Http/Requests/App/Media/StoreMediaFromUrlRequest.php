<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use App\Enums\Media\Source;
use App\Services\Media\RemoteMediaImporter;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One Unsplash photo picked in the composer: the image URL on the
 * configured download hosts, the photo's `download_location` on the
 * configured API host, and the attribution kept on the item.
 */
class StoreMediaFromUrlRequest extends FormRequest
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
            'url' => ['bail', 'required', 'string', 'url:https', $this->onHosts(Source::Unsplash->downloadHosts())],
            'filename' => ['required', 'string', 'max:255'],
            'download_location' => ['bail', 'required', 'string', 'url:https', $this->onHosts([$this->configuredHost('trypost.media_sources.unsplash.api')])],
            'photo_id' => ['required', 'string', 'max:255'],
            'author_name' => ['required', 'string', 'max:255'],
            'author_url' => ['bail', 'nullable', 'string', 'url:https', 'max:2048', $this->onHosts([$this->configuredHost('trypost.media_sources.unsplash.website')])],
        ];
    }

    /**
     * @return array{photo_id: string, author_name: string, author_url: ?string}
     */
    public function attribution(): array
    {
        return [
            'photo_id' => (string) $this->validated('photo_id'),
            'author_name' => (string) $this->validated('author_name'),
            'author_url' => $this->validated('author_url'),
        ];
    }

    /**
     * @param  list<string>  $hosts
     */
    private function onHosts(array $hosts): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hosts): void {
            if (! Source::Unsplash->isEnabled() || ! app(RemoteMediaImporter::class)->hostAllowed((string) $value, array_values(array_filter($hosts)))) {
                $fail(__('validation.url', ['attribute' => $attribute]));
            }
        };
    }

    private function configuredHost(string $key): string
    {
        return (string) parse_url((string) config($key), PHP_URL_HOST);
    }
}
