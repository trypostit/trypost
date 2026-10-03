<?php

declare(strict_types=1);

namespace App\Rules;

use App\Enums\Media\Type;
use App\Support\HeicConverter;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;

/**
 * Fails a HEIC/HEIF upload with a clear message when this install cannot
 * convert it. Takes an uploaded file or a file name; any other value passes
 * through to the rules that follow.
 */
class HeicAccepted implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value instanceof UploadedFile && HeicConverter::isSequenceMime($value->getMimeType())) {
            $fail('posts.composer.upload_errors.heic_invalid')->translate();

            return;
        }

        if (HeicConverter::available()) {
            return;
        }

        $isHeic = $value instanceof UploadedFile
            ? HeicConverter::isHeic($value->getMimeType(), $value->getClientOriginalExtension())
            : is_string($value) && HeicConverter::isHeicExtension(Type::extensionOf($value));

        if ($isHeic) {
            $fail('posts.composer.upload_errors.heic_unavailable')->translate();
        }
    }
}
