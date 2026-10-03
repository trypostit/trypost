<?php

declare(strict_types=1);

namespace App\Enums\YouTube;

use Illuminate\Support\Str;

/**
 * `snippet.categoryId` on videos.insert, limited to the assignable categories
 * of the US region, in the order the composer lists them.
 *
 * @see https://developers.google.com/youtube/v3/docs/videoCategories/list
 */
enum Category: string
{
    case AutosAndVehicles = '2';
    case Comedy = '23';
    case Education = '27';
    case Entertainment = '24';
    case Gaming = '20';
    case HowtoAndStyle = '26';
    case FilmAndAnimation = '1';
    case Music = '10';
    case NewsAndPolitics = '25';
    case NonprofitsAndActivism = '29';
    case PeopleAndBlogs = '22';
    case PetsAndAnimals = '15';
    case ScienceAndTechnology = '28';
    case Sports = '17';
    case TravelAndEvents = '19';

    public const DEFAULT = self::PeopleAndBlogs;

    public function labelKey(): string
    {
        return 'posts.form.youtube.categories.'.Str::snake($this->name);
    }

    /**
     * @return list<array{value: string, labelKey: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $category): array => ['value' => $category->value, 'labelKey' => $category->labelKey()],
            self::cases(),
        );
    }
}
