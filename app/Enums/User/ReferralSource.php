<?php

declare(strict_types=1);

namespace App\Enums\User;

enum ReferralSource: string
{
    case Google = 'google';
    case X = 'x';
    case LinkedIn = 'linkedin';
    case YouTube = 'youtube';
    case TikTok = 'tiktok';
    case Instagram = 'instagram';
    case Reddit = 'reddit';
    case ProductHunt = 'product_hunt';
    case AiAssistant = 'ai_assistant';
    case Friend = 'friend';
    case Blog = 'blog';
    case Other = 'other';
}
