<?php

declare(strict_types=1);

namespace App\Http\Requests\App\Webhook;

use App\Enums\Webhook\EventType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreWebhookRequest extends FormRequest
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
            'endpoint' => ['required', 'url', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(EventType::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'endpoint' => __('webhooks.create.endpoint'),
            'events' => __('webhooks.create.events'),
            'events.*' => __('webhooks.create.events'),
        ];
    }
}
