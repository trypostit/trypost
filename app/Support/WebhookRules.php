<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\Webhook\EventType;
use App\Enums\Webhook\Status;
use Illuminate\Validation\Rule;

/**
 * Single source of truth for webhook create/update validation, shared by the
 * web, public REST API, and MCP entry points.
 */
class WebhookRules
{
    /**
     * @return list<string>
     */
    public static function eventValues(): array
    {
        return array_column(EventType::cases(), 'value');
    }

    /**
     * @return array<string, mixed>
     */
    public static function store(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:255'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(EventType::class)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function update(): array
    {
        return [
            'endpoint' => ['sometimes', 'url', 'max:255'],
            'events' => ['sometimes', 'array', 'min:1'],
            'events.*' => ['string', Rule::enum(EventType::class)],
            'status' => ['sometimes', 'string', Rule::enum(Status::class)->only([Status::Enabled, Status::Disabled])],
        ];
    }
}
