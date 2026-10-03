<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Integration;

use App\Enums\Media\Source;
use App\Services\Media\Sources\GoogleMediaOAuth;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StartGoogleMediaRequest extends FormRequest
{
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
            'source' => ['required', 'string', Rule::in($this->enabledSources())],
            'nonce' => ['required', 'string', 'regex:'.CreateCanvaDesignRequest::NONCE_PATTERN],
        ];
    }

    public function source(): Source
    {
        return Source::from((string) $this->validated('source'));
    }

    /**
     * The composer's id for this popup; the result is delivered under it.
     */
    public function nonce(): string
    {
        return (string) $this->validated('nonce');
    }

    /**
     * @return list<string>
     */
    private function enabledSources(): array
    {
        return collect(array_keys(GoogleMediaOAuth::SCOPES))
            ->filter(fn (string $source): bool => Source::from($source)->isEnabled())
            ->values()
            ->all();
    }
}
