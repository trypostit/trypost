<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\PostNoteReaction;

use App\Rules\SingleEmoji;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class DestroyPostNoteReactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless($this->route('note')->post_id === $this->route('post')->id, Response::HTTP_NOT_FOUND);

        $gate = Gate::forUser($this->user());

        $gate->authorize('view', $this->route('post'));
        $gate->authorize('createPost', $this->route('post')->workspace);

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
