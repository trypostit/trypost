<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;

/**
 * Mirrors the entry folders (`<slug>/entry.md` plus a 16:9 `<slug>/cover.png`)
 * into `changelog_entries`. Every folder is read and validated before anything
 * is written, and a row whose folder is gone is deleted.
 */
class SyncChangelogEntries
{
    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public static function execute(string $directory): array
    {
        $entries = collect(File::isDirectory($directory) ? File::directories($directory) : [])
            ->map(fn (string $folder): array => self::read($folder));

        return DB::transaction(fn (): array => [
            ...self::save($entries),
            'deleted' => ChangelogEntry::query()->whereNotIn('slug', $entries->pluck('slug'))->delete(),
        ]);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return array{created: int, updated: int}
     */
    private static function save(Collection $entries): array
    {
        $models = $entries->map(
            fn (array $entry): ChangelogEntry => ChangelogEntry::firstOrNew(['slug' => data_get($entry, 'slug')])->fill($entry),
        );

        $counts = [
            'created' => $models->reject->exists->count(),
            'updated' => $models->filter(fn (ChangelogEntry $model): bool => $model->exists && $model->isDirty())->count(),
        ];

        $models->each->save();

        return $counts;
    }

    /**
     * @return array{slug: string, title: string, tags: list<string>, body: string, published_on: string}
     */
    private static function read(string $folder): array
    {
        $slug = File::basename($folder);

        throw_unless(File::exists("{$folder}/entry.md"), InvalidArgumentException::class, "Changelog entry [{$slug}] has no entry.md.");
        throw_unless(File::exists("{$folder}/cover.png"), InvalidArgumentException::class, "Changelog entry [{$slug}] has no cover.png.");
        throw_unless(self::isWideCover("{$folder}/cover.png"), InvalidArgumentException::class, "Changelog entry [{$slug}] needs a 16:9 cover.png (1200x675).");

        try {
            $document = (new FrontMatterParser(new SymfonyYamlFrontMatterParser))->parse(File::get("{$folder}/entry.md"));
        } catch (CommonMarkException $exception) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has invalid front matter: {$exception->getMessage()}");
        }

        $frontMatter = $document->getFrontMatter();
        $date = data_get($frontMatter, 'date');

        $validator = Validator::make([
            'title' => data_get($frontMatter, 'title'),
            'tags' => data_get($frontMatter, 'tags', []),
            'date' => is_int($date) ? CarbonImmutable::createFromTimestampUTC($date)->toDateString() : $date,
            'body' => Str::trim($document->getContent()),
        ], [
            'title' => ['required', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['distinct', Rule::enum(Tag::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'body' => ['required', 'string'],
        ]);

        throw_if($validator->fails(), InvalidArgumentException::class, "Changelog entry [{$slug}] is invalid: ".collect($validator->errors()->all())->implode(' '));

        $entry = $validator->validated();

        return [
            'slug' => $slug,
            'title' => data_get($entry, 'title'),
            'tags' => collect(data_get($entry, 'tags'))->values()->all(),
            'body' => data_get($entry, 'body'),
            'published_on' => data_get($entry, 'date'),
        ];
    }

    private static function isWideCover(string $cover): bool
    {
        [$width, $height] = getimagesize($cover) ?: [0, 0];

        return $width > 0 && $width * 9 === $height * 16;
    }
}
