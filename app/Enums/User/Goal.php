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
}
