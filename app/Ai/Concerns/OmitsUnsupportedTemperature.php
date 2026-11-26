<?php

declare(strict_types=1);

namespace App\Ai\Concerns;

/**
 * Sends the agent's preferred temperature only when the active text model
 * accepts one.
 *
 * Some models reject the parameter outright: Anthropic's Claude 5 family
 * answers "`temperature` is deprecated for this model", which fails every
 * request. Those models are listed in config('trypost.ai.models_without_temperature').
 *
 * Use this instead of the #[Temperature] attribute: laravel/ai falls back to
 * the attribute when temperature() returns null, so it would still be sent.
 */
trait OmitsUnsupportedTemperature
{
    abstract protected function preferredTemperature(): float;

    public function temperature(): ?float
    {
        return self::modelAcceptsTemperature() ? $this->preferredTemperature() : null;
    }

    public static function modelAcceptsTemperature(?string $model = null): bool
    {
        $provider = config('ai.default');
        $model ??= config("ai.providers.{$provider}.models.text.default");

        if (! is_string($model) || $model === '') {
            return true;
        }

        foreach (config('trypost.ai.models_without_temperature', []) as $pattern) {
            if (preg_match($pattern, $model) === 1) {
                return false;
            }
        }

        return true;
    }
}
