<?php

declare(strict_types=1);

namespace App\Http\Requests\App\PostNoteReaction;

use App\Rules\SingleEmoji;
use Illuminate\Foundation\Http\FormRequest;

class DestroyPostNoteReactionRequest extends FormRequest
{
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
            'emoji' => ['required', 'string', new SingleEmoji],
        ];
    }
}
