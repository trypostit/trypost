<?php

declare(strict_types=1);

namespace App\Support;

use App\Dto\LibraryTemplate;
use App\Enums\PostTemplate\Audience;
use App\Enums\PostTemplate\Format;
use App\Enums\PostTemplate\Goal;
use App\Enums\PostTemplate\Type;

class TemplateLibrary
{
    /**
     * @var list<array{0: string, 1: string, 2: string, 3: list<string>, 4: string, 5: string, 6: bool}>
     */
    private const array TEMPLATES = [
        ['quick_win', '💡', 'tip', ['business', 'individual'], 'single', 'education', true],
        ['tool_you_rely_on', '🧰', 'tip', ['individual'], 'single', 'expertise', false],
        ['mistake_to_avoid', '⚠️', 'tip', ['business', 'individual'], 'single', 'education', false],
        ['faq_answer', '🗣️', 'tip', ['business'], 'single', 'education', false],
        ['beginner_guide', '🧭', 'tip', ['individual'], 'single', 'education', false],
        ['product_how_to', '🛠️', 'tip', ['business'], 'single', 'promotion', false],
        ['repurpose_post', '🔁', 'tip', ['business', 'individual'], 'single', 'expertise', false],
        ['shortcut', '⌨️', 'tip', ['individual'], 'single', 'education', false],
        ['question_to_ask', '🧐', 'tip', ['business', 'individual'], 'single', 'reflection', false],
        ['common_setting', '⚙️', 'tip', ['business'], 'single', 'education', false],
        ['habit', '🔄', 'tip', ['individual'], 'single', 'reflection', false],
        ['quick_stat', '📊', 'tip', ['business', 'individual'], 'single', 'expertise', false],
        ['before_after', '📈', 'case_study', ['business'], 'single', 'expertise', true],
        ['customer_spotlight', '🌟', 'case_study', ['business'], 'single', 'connection', false],
        ['experiment_results', '🧪', 'story', ['business', 'individual'], 'thread', 'education', false],
        ['origin_story', '🌱', 'story', ['business', 'individual'], 'single', 'connection', true],
        ['lesson_learned', '📚', 'story', ['individual'], 'single', 'reflection', false],
        ['milestone_story', '🏁', 'story', ['business', 'individual'], 'single', 'celebration', false],
        ['failure_story', '🔧', 'story', ['business', 'individual'], 'thread', 'reflection', false],
        ['first_customer', '🤝', 'story', ['business'], 'single', 'connection', false],
        ['hard_decision', '🪨', 'story', ['individual'], 'single', 'reflection', false],
        ['unexpected_win', '🍀', 'story', ['business', 'individual'], 'single', 'celebration', false],
        ['mentor_moment', '🎓', 'story', ['individual'], 'single', 'connection', false],
        ['almost_quit', '🌧️', 'story', ['individual'], 'thread', 'reflection', false],
        ['customer_letter', '✉️', 'story', ['business'], 'single', 'connection', false],
        ['turning_point', '🔀', 'story', ['business', 'individual'], 'single', 'reflection', false],
        ['behind_a_launch', '🚀', 'story', ['business'], 'thread', 'promotion', false],
        ['year_in_review', '📅', 'story', ['business', 'individual'], 'single', 'celebration', false],
        ['why_we_do_it', '❤️', 'story', ['business'], 'single', 'connection', false],
        ['small_moment', '✨', 'story', ['individual'], 'single', 'connection', false],
        ['first_attempt', '🎯', 'story', ['individual'], 'single', 'education', false],
        ['step_by_step', '🪜', 'how_to', ['business', 'individual'], 'thread', 'education', false],
        ['framework', '🧩', 'how_to', ['business', 'individual'], 'thread', 'expertise', false],
        ['this_or_that', '⚖️', 'question', ['business', 'individual'], 'single', 'engagement', false],
        ['ask_for_advice', '🙋', 'question', ['individual'], 'single', 'connection', false],
        ['unpopular_opinion', '🔥', 'opinion', ['individual'], 'single', 'engagement', false],
        ['industry_prediction', '🔮', 'opinion', ['business', 'individual'], 'thread', 'expertise', false],
        ['myth_busting', '🚫', 'opinion', ['business', 'individual'], 'single', 'education', false],
        ['trend_take', '🌊', 'opinion', ['individual'], 'single', 'reflection', false],
        ['resources_list', '🔗', 'list', ['business', 'individual'], 'single', 'education', false],
        ['lessons_list', '📝', 'list', ['individual'], 'thread', 'reflection', false],
        ['checklist', '✅', 'list', ['business', 'individual'], 'single', 'expertise', false],
        ['day_in_the_life', '☕', 'behind_the_scenes', ['business', 'individual'], 'single', 'connection', false],
        ['meet_the_team', '👋', 'behind_the_scenes', ['business'], 'single', 'connection', false],
        ['work_in_progress', '🚧', 'behind_the_scenes', ['business', 'individual'], 'single', 'promotion', false],
    ];

    /**
     * @return list<LibraryTemplate>
     */
    public static function all(): array
    {
        return array_map(fn (array $row): LibraryTemplate => self::make($row), self::TEMPLATES);
    }

    /**
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_column(self::TEMPLATES, 0);
    }

    public static function find(string $key): ?LibraryTemplate
    {
        $index = array_search($key, self::keys(), true);

        return $index === false ? null : self::make(self::TEMPLATES[$index]);
    }

    /**
     * @param  array{0: string, 1: string, 2: string, 3: list<string>, 4: string, 5: string, 6: bool}  $row
     */
    private static function make(array $row): LibraryTemplate
    {
        return new LibraryTemplate(
            key: $row[0],
            emoji: $row[1],
            type: Type::from($row[2]),
            audiences: array_map(fn (string $audience): Audience => Audience::from($audience), $row[3]),
            format: Format::from($row[4]),
            goal: Goal::from($row[5]),
            featured: $row[6],
        );
    }

    /**
     * @return list<LibraryTemplate>
     */
    public static function featured(): array
    {
        return array_values(array_filter(
            self::all(),
            fn (LibraryTemplate $template): bool => $template->featured,
        ));
    }

    /**
     * @param  array{search?: ?string, types?: list<Type>, audiences?: list<Audience>, formats?: list<Format>, goals?: list<Goal>}  $filters
     * @return list<LibraryTemplate>
     */
    public static function filter(array $filters): array
    {
        $search = trim((string) data_get($filters, 'search', ''));
        $types = data_get($filters, 'types', []);
        $audiences = data_get($filters, 'audiences', []);
        $formats = data_get($filters, 'formats', []);
        $goals = data_get($filters, 'goals', []);

        return array_values(array_filter(
            self::all(),
            fn (LibraryTemplate $template): bool => ($types === [] || in_array($template->type, $types, true))
                && ($audiences === [] || array_filter($template->audiences, fn (Audience $audience): bool => in_array($audience, $audiences, true)) !== [])
                && ($formats === [] || in_array($template->format, $formats, true))
                && ($goals === [] || in_array($template->goal, $goals, true))
                && ($search === ''
                    || mb_stripos($template->title(), $search) !== false
                    || mb_stripos($template->description(), $search) !== false),
        ));
    }
}
