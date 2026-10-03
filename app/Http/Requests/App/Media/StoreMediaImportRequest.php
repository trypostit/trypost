<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use App\Dto\RemoteFile;
use App\Enums\Media\Source;
use App\Services\Media\Sources\GoogleDriveFiles;
use App\Services\Media\Sources\GooglePhotosPicker;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * One pick per request. A picker source is importable once it has an arm
 * here (its own rules and the payload the job downloads) and is enabled.
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

    public function importSource(): Source
    {
        return Source::from((string) $this->validated('source'));
    }

    public function importPayload(): RemoteFile|string
    {
        $arm = data_get($this->arms(), $this->importSource()->value) ?? throw new LogicException("No import arm for {$this->importSource()->value}.");

        return data_get($arm, 'payload')();
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
     * @return array<string, array{rules: Closure(): array<string, array<int, mixed>>, payload: Closure(): (RemoteFile|string)}>
     */
    private function arms(): array
    {
        return [
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
            ],
        ];
    }
}
