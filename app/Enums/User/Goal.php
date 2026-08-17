<?php

declare(strict_types=1);

namespace App\Enums\User;

enum Goal: string
{
    case SaveTime = 'save_time';
    case AiContent = 'ai_content';
    case UseMcp = 'use_mcp';
    case PlanCalendar = 'plan_calendar';
    case StayOnBrand = 'stay_on_brand';
    case GrowAudience = 'grow_audience';
    case DriveSales = 'drive_sales';
    case ManageClients = 'manage_clients';
    case JustExploring = 'just_exploring';
    case Other = 'other';

    /**
     * True when at least one stored goal still exists as a Goal case.
     * Dropped values must not count — users mid-funnel would otherwise
     * skip re-selecting after we slim the list.
     *
     * @param  list<string>|null  $goals
     */
    public static function containsCurrent(?array $goals): bool
    {
        if (! is_array($goals) || $goals === []) {
            return false;
        }

        $allowed = array_map(fn (self $goal): string => $goal->value, self::cases());

        return array_intersect($goals, $allowed) !== [];
    }
}
