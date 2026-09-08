<?php

declare(strict_types=1);

namespace App\Enums\Repurpose;

enum PublishMode: string
{
    case Publish = 'publish';
    case Draft = 'draft';

    public function label(): string
    {
        return __("repurposes.publish_modes.{$this->value}");
    }

    public function description(): string
    {
        return __("repurposes.publish_modes.{$this->value}_hint");
    }
}
