<?php

declare(strict_types=1);

namespace App\Enums\Plan;

enum Slug: string
{
    case Workspace = 'workspace';
    case Socials = 'socials';
    case Workspaces = 'workspaces';

    public function label(): string
    {
        return match ($this) {
            self::Workspace => 'Workspace',
            self::Socials => 'Socials',
            self::Workspaces => 'Workspaces',
        };
    }
}
