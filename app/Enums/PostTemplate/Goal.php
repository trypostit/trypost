<?php

declare(strict_types=1);

namespace App\Enums\PostTemplate;

enum Goal: string
{
    case Engagement = 'engagement';
    case Connection = 'connection';
    case Reflection = 'reflection';
    case Expertise = 'expertise';
    case Promotion = 'promotion';
    case Education = 'education';
    case Celebration = 'celebration';
}
