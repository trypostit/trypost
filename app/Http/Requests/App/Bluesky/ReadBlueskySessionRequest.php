<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Bluesky;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A Bluesky session as `createSession` or `getSession` answers it. A session
 * lookup carries no tokens; a value is never cast into its type.
 */
class ReadBlueskySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `emailConfirmed` may be missing or null; `required_unless` makes a blank
     * string fail, since `boolean:strict` alone skips empty values.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'did' => ['required', 'string'],
            'handle' => ['required', 'string'],
            'accessJwt' => ['sometimes', 'required', 'string'],
            'refreshJwt' => ['sometimes', 'required', 'string'],
            'emailConfirmed' => ['nullable', 'required_unless:emailConfirmed,null', 'boolean:strict'],
        ];
    }
}
