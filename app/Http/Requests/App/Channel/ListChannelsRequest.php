<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use App\Enums\SocialAccount\Platform;
use Illuminate\Foundation\Http\FormRequest;

class ListChannelsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageAccounts', $this->user()->currentWorkspace);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:255'],
            'networks' => ['nullable', 'array'],
            'networks.*' => ['string'],
        ];
    }

    public function search(): string
    {
        return $this->string('search')->trim()->toString();
    }

    /**
     * The networks asked for that exist ("instagram", "linkedin"), each once.
     *
     * @return list<string>
     */
    public function networks(): array
    {
        $known = collect(Platform::cases())->map(fn (Platform $platform): string => $platform->network())->unique();

        return $this->collect('networks')
            ->filter(fn (mixed $network): bool => $known->containsStrict($network))
            ->unique()
            ->values()
            ->all();
    }
}
