<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Integration;

use App\Enums\Media\CanvaPreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateCanvaDesignRequest extends FormRequest
{
    public const string NONCE_PATTERN = '/^[A-Za-z0-9_-]{16,64}$/';

    public function authorize(): bool
    {
        return $this->user()->can('createPost', $this->user()->currentWorkspace);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'preset' => ['required', 'string', Rule::enum(CanvaPreset::class)],
            'nonce' => ['required', 'string', 'regex:'.self::NONCE_PATTERN],
        ];
    }

    /**
     * The composer's id for this popup; the result is delivered under it.
     */
    public function nonce(): string
    {
        return (string) $this->validated('nonce');
    }

    public function preset(): CanvaPreset
    {
        return CanvaPreset::from((string) $this->validated('preset'));
    }
}
