<?php

declare(strict_types=1);

namespace App\Enums\PostTemplate;

enum Type: string
{
    case Tip = 'tip';
    case CaseStudy = 'case_study';
    case Story = 'story';
    case HowTo = 'how_to';
    case Question = 'question';
    case Opinion = 'opinion';
    case List = 'list';
    case BehindTheScenes = 'behind_the_scenes';
}
