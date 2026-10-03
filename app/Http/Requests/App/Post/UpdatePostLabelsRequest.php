<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Post;

use App\Models\Post;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostLabelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Post $post */
        $post = $this->route('post');

        return [
            'labels' => ['present', 'array'],
            'labels.*' => ['uuid', 'distinct', Rule::exists('workspace_labels', 'id')->where('workspace_id', $post->workspace_id)->withoutTrashed()],
        ];
    }
}
