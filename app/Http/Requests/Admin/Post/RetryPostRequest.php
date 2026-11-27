<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin\Post;

use Illuminate\Foundation\Http\FormRequest;

class RetryPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'instagram_comment_checked' => ['sometimes', 'boolean'],
        ];
    }

    /** The admin checked on Instagram that the post's first comment is missing. */
    public function instagramCommentChecked(): bool
    {
        return $this->boolean('instagram_comment_checked');
    }
}
