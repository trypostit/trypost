<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Media;

use App\Enums\Media\Type as MediaType;
use App\Rules\HeicAccepted;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chunked upload request. The chunk metadata (offset / total
 * size / filename) is encoded in the `Content-Range` and `X-File-Name`
 * headers, not in the body, so we lift it into the request bag via
 * `prepareForValidation` and then run standard rules against it.
 */
class StoreChunkedMediaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('createPost', $this->user()->currentWorkspace);
    }

    protected function prepareForValidation(): void
    {
        $parsed = sscanf((string) $this->header('Content-Range'), 'bytes %d-%d/%d') ?: [];

        $this->merge([
            'range_start' => $parsed[0] ?? null,
            'range_end' => $parsed[1] ?? null,
            'total_size' => $parsed[2] ?? null,
            'file_name' => strtolower(rawurldecode((string) $this->header('X-File-Name', 'upload'))),
            'upload_id' => $this->header('X-Upload-Id'),
            'duration' => $this->header('X-Media-Duration'),
        ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $allowedSuffixes = collect([MediaType::Image, MediaType::Video, MediaType::Document])
            ->flatMap(fn (MediaType $type) => $type->extensions())
            ->map(fn (string $ext) => '.'.$ext)
            ->all();

        return [
            'range_start' => ['required', 'integer', 'min:0'],
            'range_end' => ['required', 'integer', 'gte:range_start'],
            'total_size' => ['required', 'integer', 'min:1', 'max:'.$this->declaredType()->maxSizeInBytes()],
            'file_name' => ['bail', 'required', 'string', new HeicAccepted, 'ends_with:'.implode(',', $allowedSuffixes)],
            'upload_id' => ['required', 'string', 'uuid'],
            // Capped at a day so `1e999` (INF, which JSON cannot encode) never reaches the meta column.
            'duration' => ['nullable', 'numeric', 'min:0', 'max:86400'],
        ];
    }

    /**
     * The type the file name announces. Its cap bounds the declared size; the
     * receiver re-checks the assembled bytes against the detected type.
     */
    public function declaredType(): MediaType
    {
        return MediaType::fromExtension(MediaType::extensionOf((string) $this->input('file_name'))) ?? MediaType::Video;
    }

    public function duration(): ?float
    {
        return transform($this->validated('duration'), fn (mixed $seconds) => (float) $seconds);
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'total_size.max' => __('posts.composer.upload_errors.too_large', ['size' => $this->declaredType()->maxSizeInMb()]),
        ];
    }
}
