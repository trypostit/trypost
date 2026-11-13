<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Channel;

use App\Actions\SocialAccount\ListWorkspaceChannels;
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
     * The networks asked for that the workspace has a channel on ("instagram",
     * "linkedin"), each once, so a filter left on a network the workspace no
     * longer has is dropped instead of hiding every channel.
     *
     * @return list<string>
     */
    public function networks(): array
    {
        $requested = $this->collect('networks');

        if ($requested->isEmpty()) {
            return [];
        }

        $available = ListWorkspaceChannels::networks($this->user()->currentWorkspace);

        return $requested
            ->filter(fn (mixed $network): bool => in_array($network, $available, true))
            ->unique()
            ->values()
            ->all();
    }
}
