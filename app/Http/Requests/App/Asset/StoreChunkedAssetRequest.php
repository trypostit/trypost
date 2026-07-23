<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Asset;

use App\Enums\Media\Type as MediaType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a chunked upload request. The chunk metadata (offset / total
 * size / filename) is encoded in the `Content-Range` and `X-File-Name`
 * headers, not in the body, so we lift it into the request bag via
 * `prepareForValidation` and then run standard rules against it.
 */
class StoreChunkedAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $parsed = sscanf((string) $this->header('Content-Range'), 'bytes %d-%d/%d') ?: [];

        $this->merge([
            'range_start' => $parsed[0] ?? null,
            'range_end' => $parsed[1] ?? null,
            'total_size' => $parsed[2] ?? null,
            'file_name' => strtolower(rawurldecode((string) $this->header('X-File-Name', 'upload'))),
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
            'total_size' => ['required', 'integer', 'min:1', 'max:'.MediaType::Video->maxSizeInBytes()],
            'file_name' => ['required', 'string', 'ends_with:'.implode(',', $allowedSuffixes)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'range_start.required' => 'Invalid Content-Range header',
            'range_end.required' => 'Invalid Content-Range header',
            'total_size.required' => 'Invalid Content-Range header',
            'total_size.max' => 'File size exceeds the maximum allowed ('.MediaType::Video->maxSizeInMb().' MB).',
            'file_name.ends_with' => 'File type not supported.',
        ];
    }
}
