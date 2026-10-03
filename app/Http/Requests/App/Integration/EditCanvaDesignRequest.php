<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Integration;

use App\Enums\Media\Source;
use App\Models\Media;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Inertia\Inertia;

class EditCanvaDesignRequest extends FormRequest
{
    private ?Media $resolvedMedia = null;

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
            'media' => ['required', 'string', 'uuid', function (string $attribute, mixed $value, Closure $fail): void {
                if ($this->findMedia((string) $value) === null) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
            'nonce' => ['required', 'string', 'regex:'.CreateCanvaDesignRequest::NONCE_PATTERN],
        ];
    }

    /**
     * The request runs inside the Canva popup, so a refusal is shown there
     * and reported to the composer instead of redirecting back.
     */
    protected function failedValidation(Validator $validator): never
    {
        $nonce = $this->query('nonce');

        throw new HttpResponseException(Inertia::render('integrations/MediaSourcePopup', [
            'source' => Source::Canva->value,
            'nonce' => is_string($nonce) && preg_match(CreateCanvaDesignRequest::NONCE_PATTERN, $nonce) === 1 ? $nonce : null,
            'success' => false,
            'importId' => null,
            'replaces' => null,
            'message' => __('posts.composer.media_sources.errors.canva_edit_failed'),
        ])->toResponse($this));
    }

    /**
     * The composer's id for this popup; the result is delivered under it.
     */
    public function nonce(): string
    {
        return (string) $this->validated('nonce');
    }

    /**
     * The Canva-made row of the current workspace, owned or a temporary upload.
     */
    public function media(): Media
    {
        return $this->findMedia((string) $this->validated('media'));
    }

    public function designId(): string
    {
        return (string) data_get($this->media()->meta, 'source_meta.design_id');
    }

    private function findMedia(string $id): ?Media
    {
        if ($this->resolvedMedia?->id === $id) {
            return $this->resolvedMedia;
        }

        $media = Media::query()
            ->where('workspace_id', $this->user()->currentWorkspace->id)
            ->whereKey($id)
            ->first();

        $designId = data_get($media?->meta, 'source_meta.design_id');

        if (! is_string($designId) || $designId === '') {
            return null;
        }

        return $this->resolvedMedia = $media;
    }
}
