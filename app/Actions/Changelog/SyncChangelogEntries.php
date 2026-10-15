<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Http\Requests\App\Changelog\SyncChangelogEntryRequest;
use App\Models\ChangelogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;

/**
 * Mirrors the entry folders (`<YYYY-MM-DD>-<slug>/entry.md` plus a 16:9
 * `cover.png`) into `changelog_entries`. Every folder is read and validated
 * against SyncChangelogEntryRequest before anything is written, a row whose
 * folder is gone is deleted, and any change drops the cached sidebar cards.
 */
class SyncChangelogEntries
{
    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public static function execute(string $directory): array
    {
        throw_unless(File::isDirectory($directory), InvalidArgumentException::class, "The changelog folder [{$directory}] does not exist.");

        $entries = collect(File::directories($directory))->map(fn (string $folder): array => self::read($folder));

        $result = DB::transaction(fn (): array => [
            ...self::save($entries),
            'deleted' => ChangelogEntry::query()->whereNotIn('slug', $entries->pluck('slug'))->delete(),
        ]);

        if (array_sum($result) > 0) {
            ShowChangelogCard::forgetAll();
        }

        return $result;
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

        throw_if(File::missing("{$folder}/entry.md"), InvalidArgumentException::class, "Changelog entry [{$slug}] has no entry.md.");
        throw_if(File::missing("{$folder}/cover.png"), InvalidArgumentException::class, "Changelog entry [{$slug}] has no cover.png.");

        try {
            $document = (new FrontMatterParser(new SymfonyYamlFrontMatterParser))->parse(File::get("{$folder}/entry.md"));
        } catch (CommonMarkException $exception) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has invalid front matter: {$exception->getMessage()}");
        }

        $date = data_get($document->getFrontMatter(), 'date');

        $request = (new SyncChangelogEntryRequest)->merge([
            'slug' => $slug,
            'cover' => new HttpFile("{$folder}/cover.png"),
            'title' => data_get($document->getFrontMatter(), 'title'),
            'tags' => transform(data_get($document->getFrontMatter(), 'tags'), fn (mixed $tags): mixed => $tags, []),
            'date' => is_int($date) ? CarbonImmutable::createFromTimestampUTC($date)->toDateString() : $date,
            'body' => Str::trim($document->getContent()),
        ]);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());
        $errors = collect($validator->errors()->all())->implode(' ');

        throw_if($validator->fails(), InvalidArgumentException::class, "Changelog entry [{$slug}] is invalid: {$errors}");

        $entry = $validator->validated();

        return [
            'slug' => $slug,
            'title' => data_get($entry, 'title'),
            'tags' => data_get($entry, 'tags'),
            'body' => data_get($entry, 'body'),
            'published_on' => data_get($entry, 'date'),
        ];
    }
}
