<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Actions\Media\ResolveWorkspaceMedia;
use App\Models\Media;
use App\Support\PostMediaRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AttachMediaFromUploadRequest extends FormRequest
{
    private ?Media $resolvedUpload = null;

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
            'upload_token' => ['required', 'uuid'],
            'alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('upload_token')) {
                return;
            }

            $this->resolvedUpload = ResolveWorkspaceMedia::byUploadTokens(
                $this->user()->currentWorkspace,
                [(string) $this->input('upload_token')],
            )->first();

            if ($this->resolvedUpload === null) {
                $validator->errors()->add('upload_token', __('posts.errors.media_expired'));
            }
        });
    }

    public function upload(): Media
    {
        return $this->resolvedUpload;
    }
}
