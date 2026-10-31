<?php

declare(strict_types=1);

namespace App\Enums\Analytics;

/**
 * The parts of an Insights PDF the user can leave out: the sections of each
 * Insights page, in the page's order, since the PDF prints the page itself.
 */
enum PdfSection: string
{
    case Summary = 'summary';
    case TopPostsByReactions = 'top_posts_reactions';
    case TopPostsByComments = 'top_posts_comments';
    case Performance = 'performance';
    case Followers = 'followers';
    case PostsOverTime = 'posts_over_time';
    case Metrics = 'metrics';
    case Posts = 'posts';

    /**
     * @return list<self>
     */
    public static function forWorkspace(): array
    {
        return [self::Summary, self::TopPostsByReactions, self::TopPostsByComments, self::Performance, self::Followers, self::PostsOverTime];
    }

    /**
     * @return list<self>
     */
    public static function forChannel(): array
    {
        return [self::Summary, self::Posts, self::Metrics];
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
