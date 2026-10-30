<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

/**
 * The parts of an Insights PDF the user can leave out. The workspace report and a
 * channel's report offer different ones, like the two Insights pages.
 */
enum PdfSection: string
{
    case Summary = 'summary';
    case TopPostsByReactions = 'top_posts_reactions';
    case TopPostsByComments = 'top_posts_comments';
    case Performance = 'performance';
    case Channels = 'channels';
    case Metrics = 'metrics';
    case Posts = 'posts';

    /**
     * @return list<self>
     */
    public static function forWorkspace(): array
    {
        return [self::Summary, self::TopPostsByReactions, self::TopPostsByComments, self::Performance, self::Channels];
    }

    /**
     * @return list<self>
     */
    public static function forChannel(): array
    {
        return [self::Summary, self::Metrics, self::Posts];
    }

    /**
     * @param  list<self>  $sections
     * @return list<string>
     */
    public static function values(array $sections): array
    {
        return array_map(fn (self $section): string => $section->value, $sections);
    }
}
