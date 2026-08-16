<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Post;

use App\Actions\Media\FindWorkspaceAsset;
use App\Actions\Post\AttachExistingAsset;
use App\Models\Media;
use App\Models\Post;
use App\Support\PostMediaRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;
use Symfony\Component\HttpFoundation\Response;

class AttachExistingAssetRequest extends FormRequest
{
    private ?Media $resolvedAsset = null;

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
            'asset_id' => ['required', 'uuid'],
            'alt' => ['nullable', 'string', 'max:'.PostMediaRules::ALT_TEXT_MAX_LENGTH],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('asset_id')) {
                return;
            }

            $post = $this->route('post');
            $workspace = $this->user()?->currentWorkspace;

            if (! $post instanceof Post || $workspace === null) {
                $validator->errors()->add('asset_id', AttachExistingAsset::ASSET_NOT_FOUND_MESSAGE);

                return;
            }

            // Cross-tenant posts must 404 from PostPolicy, not 422 from a
            // type check against the foreign post's platforms.
            if ($post->workspace_id !== $this->user()?->current_workspace_id) {
                return;
            }

            $asset = FindWorkspaceAsset::execute($workspace, (string) $this->input('asset_id'));

            if ($asset === null) {
                $validator->errors()->add('asset_id', AttachExistingAsset::ASSET_NOT_FOUND_MESSAGE);

                return;
            }

            if (! in_array($asset->type, $post->allowedMediaTypes(), true)) {
                $validator->errors()->add(
                    'asset_id',
                    AttachExistingAsset::UNSUPPORTED_TYPE_MESSAGE,
                );

                return;
            }

            $this->resolvedAsset = $asset;
        });
    }

    public function asset(): Media
    {
        abort_if($this->resolvedAsset === null, Response::HTTP_NOT_FOUND);

        return $this->resolvedAsset;
    }
}
