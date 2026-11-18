<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Enums\Media\Type as MediaType;
use App\Services\Media\RemoteMediaImporter;
use App\Services\Media\Sources\GoogleDriveFiles;
use App\Services\Media\Sources\GooglePhotosPicker;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Uri;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * One pick per request. A picker source is importable once it has an arm
 * here (its own rules, the payload the job downloads and the media types
 * it may be) and is enabled.
 */
class StoreMediaImportRequest extends FormRequest
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
        $arm = data_get($this->arms(), (string) $this->input('source'));

        return [
            'source' => ['bail', 'required', 'string', Rule::in(array_map(fn (Source $source): string => $source->value, $this->importableSources()))],
            'files' => ['prohibited'],
            ...($arm === null ? [] : data_get($arm, 'rules')()),
        ];
    }

    private function importSource(): Source
    {
        return Source::from((string) $this->validated('source'));
    }

    public function importPayload(): RemoteFile|string
    {
        return data_get($this->arm(), 'payload')();
    }

    /**
     * The media types the pick may be.
     *
     * @return list<MediaType>
     */
    public function importTypes(): array
    {
        return data_get($this->arm(), 'types');
    }

    /**
     * @return array{rules: Closure(): array<string, array<int, mixed>>, payload: Closure(): (RemoteFile|string), types: list<MediaType>}
     */
    private function arm(): array
    {
        return data_get($this->arms(), $this->importSource()->value) ?? throw new LogicException("No import arm for {$this->importSource()->value}.");
    }

    /**
     * @return list<Source>
     */
    private function importableSources(): array
    {
        return collect(Source::pickerSources())
            ->filter(fn (Source $source): bool => $source->isEnabled() && array_key_exists($source->value, $this->arms()))
            ->values()
            ->all();
    }

    /**
     * Per-source rules and payload, keyed by the source value.
     *
     * @return array<string, array{rules: Closure(): array<string, array<int, mixed>>, payload: Closure(): (RemoteFile|string), types: list<MediaType>}>
     */
    private function arms(): array
    {
        return [
            Source::Giphy->value => [
                'rules' => fn (): array => [
                    'url' => ['bail', 'required', 'string', 'url:https', $this->onHosts(Source::Giphy->downloadHosts())],
                    'gif_id' => ['required', 'string', 'alpha_num', 'max:64'],
                    'giphy_url' => ['bail', 'nullable', 'string', 'url:https', 'max:2048', $this->onHosts([$this->configuredHost('trypost.media_sources.giphy.website')])],
                ],
                'payload' => fn (): RemoteFile => new RemoteFile(
                    $this->safe()->string('url')->value(),
                    "giphy-{$this->safe()->string('gif_id')}.gif",
                    allowedHosts: Source::Giphy->downloadHosts(),
                    source: Source::Giphy,
                    sourceMeta: $this->safe()->only(['gif_id', 'giphy_url']),
                ),
                'types' => [MediaType::Image],
            ],
            Source::Unsplash->value => [
                'rules' => fn (): array => [
                    'url' => ['bail', 'required', 'string', 'url:https', $this->onHosts(Source::Unsplash->downloadHosts())],
                    'filename' => ['required', 'string', 'max:255'],
                    'download_location' => ['bail', 'required', 'string', 'url:https', $this->onHosts([$this->configuredHost('trypost.media_sources.unsplash.api')])],
                    'photo_id' => ['required', 'string', 'max:255'],
                    'author_name' => ['required', 'string', 'max:255'],
                    'author_url' => ['bail', 'nullable', 'string', 'url:https', 'max:2048', $this->onHosts([$this->configuredHost('trypost.media_sources.unsplash.website')])],
                ],
                'payload' => fn (): RemoteFile => new RemoteFile(
                    $this->safe()->string('url')->value(),
                    $this->safe()->string('filename')->value(),
                    allowedHosts: Source::Unsplash->downloadHosts(),
                    source: Source::Unsplash,
                    sourceMeta: $this->safe()->only(['photo_id', 'author_name', 'author_url', 'download_location']),
                ),
                'types' => [MediaType::Image],
            ],
            Source::GoogleDrive->value => [
                'rules' => fn (): array => [
                    'access_token' => ['required', 'string', 'max:4096'],
                    'file' => ['required', 'array:id,name'],
                    'file.id' => ['required', 'string', 'max:255', 'regex:'.GoogleDriveFiles::FILE_ID_PATTERN],
                    'file.name' => ['required', 'string', 'max:255'],
                ],
                'payload' => fn (): RemoteFile => GoogleDriveFiles::toRemoteFile(
                    (string) $this->validated('access_token'),
                    (array) $this->validated('file'),
                ),
                'types' => MediaType::cases(),
            ],
            Source::GooglePhotos->value => [
                'rules' => fn (): array => [
                    'session_id' => [
                        'bail',
                        'required',
                        'string',
                        'max:255',
                        'regex:'.GooglePhotosPicker::SESSION_ID_PATTERN,
                        function (string $attribute, mixed $value, Closure $fail): void {
                            if (GooglePhotosPicker::token($this->user()->id, (string) $value) === null) {
                                $fail(__('posts.composer.media_sources.errors.session_expired'));
                            }
                        },
                    ],
                ],
                'payload' => fn (): string => (string) $this->validated('session_id'),
                'types' => MediaType::cases(),
            ],
        ];
    }

    /**
     * The URL must sit on one of the hosts.
     *
     * @param  list<string>  $hosts
     */
    private function onHosts(array $hosts): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($hosts): void {
            if (! app(RemoteMediaImporter::class)->hostAllowed((string) $value, $hosts)) {
                $fail(__('validation.url', ['attribute' => $attribute]));
            }
        };
    }

    private function configuredHost(string $key): string
    {
        return (string) Uri::of((string) config($key))->host();
    }
}
