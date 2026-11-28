<?php

declare(strict_types=1);

use App\Dto\LibraryTemplate;
use App\Enums\PostTemplate\Audience;
use App\Enums\PostTemplate\Format;
use App\Enums\PostTemplate\Goal;
use App\Enums\PostTemplate\Type;
use App\Enums\User\Locale;
use App\Support\TemplateLibrary;

const TEMPLATE_LIBRARY_DISTRIBUTION = [
    'tip' => 12,
    'case_study' => 2,
    'story' => 17,
    'how_to' => 2,
    'question' => 2,
    'opinion' => 4,
    'list' => 3,
    'behind_the_scenes' => 3,
];

function templateLibraryKeys(array $templates): array
{
    return array_map(fn (LibraryTemplate $template): string => $template->key, $templates);
}

test('the library has the decided shape', function () {
    $templates = TemplateLibrary::all();
    $keys = TemplateLibrary::keys();

    expect($templates)->toHaveCount(45)
        ->and(array_unique($keys))->toHaveCount(45)
        ->and(templateLibraryKeys($templates))->toBe($keys)
        ->and($keys[0])->toBe('quick_win')
        ->and($keys[44])->toBe('work_in_progress');

    foreach (TEMPLATE_LIBRARY_DISTRIBUTION as $type => $count) {
        $actual = count(array_filter($templates, fn (LibraryTemplate $template): bool => $template->type->value === $type));
        expect($actual)->toBe($count, $type);
    }

    foreach (Goal::cases() as $goal) {
        $actual = count(array_filter($templates, fn (LibraryTemplate $template): bool => $template->goal === $goal));
        expect($actual)->toBeGreaterThanOrEqual(2, $goal->value);
    }

    expect(templateLibraryKeys(TemplateLibrary::featured()))->toBe(['quick_win', 'before_after', 'origin_story']);
});

test('every template has copy in every locale', function (Locale $locale) {
    app()->setLocale($locale->value);

    foreach (TemplateLibrary::all() as $template) {
        foreach (['title', 'description', 'body'] as $field) {
            $value = $template->{$field}();
            expect($value)->not->toBe('')
                ->and($value)->not->toBe("template_library.{$template->key}.{$field}");
        }

        expect($template->body())->toContain('• ');
    }
})->with(Locale::cases());

test('english titles resolve', function () {
    expect(TemplateLibrary::find('quick_win')->title())->toBe('The five-minute fix')
        ->and(TemplateLibrary::find('first_customer')->title())->toBe('Our first customer')
        ->and(TemplateLibrary::find('missing'))->toBeNull();
});

test('filters are AND across facets and OR within a facet', function () {
    $tipsAndStories = TemplateLibrary::filter(['types' => [Type::Tip, Type::Story]]);

    expect($tipsAndStories)->toHaveCount(29)
        ->and(array_filter($tipsAndStories, fn (LibraryTemplate $template): bool => ! in_array($template->type, [Type::Tip, Type::Story], true)))->toBe([])
        ->and(TemplateLibrary::filter(['types' => [Type::Tip], 'formats' => [Format::Thread]]))->toBe([])
        ->and(templateLibraryKeys(TemplateLibrary::filter(['types' => [Type::HowTo]])))->toBe(['step_by_step', 'framework'])
        ->and(templateLibraryKeys(TemplateLibrary::filter(['audiences' => [Audience::Individual], 'goals' => [Goal::Reflection]])))->toBe([
            'question_to_ask', 'habit', 'lesson_learned', 'failure_story', 'hard_decision',
            'almost_quit', 'turning_point', 'trend_take', 'lessons_list',
        ]);
});

test('search is case insensitive and follows the locale', function () {
    expect(templateLibraryKeys(TemplateLibrary::filter(['search' => 'CHECKLIST'])))->toContain('checklist');

    app()->setLocale(Locale::PortugueseBrazil->value);
    $word = explode(' ', TemplateLibrary::find('origin_story')->title())[0];

    expect(templateLibraryKeys(TemplateLibrary::filter(['search' => $word])))->toContain('origin_story');
});
