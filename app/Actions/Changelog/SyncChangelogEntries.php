<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;

/**
 * Mirrors the Markdown entries under `changelog/<slug>/` (entry.md + cover.png)
 * into `changelog_entries`. Every folder is validated before anything is
 * written, covers go to the default disk only when their hash changed, and an
 * entry whose folder is gone is deleted with its cover.
 */
class SyncChangelogEntries
{
    /**
     * @return array{created: int, updated: int, deleted: int, uploaded: int}
     */
    public static function execute(string $directory): array
    {
        $entries = collect(File::isDirectory($directory) ? File::directories($directory) : [])
            ->sort()
            ->map(fn (string $folder): array => self::parse($folder))
            ->values();

        $result = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'uploaded' => 0];

        DB::transaction(function () use ($entries, &$result): void {
            foreach ($entries as $entry) {
                $model = ChangelogEntry::firstOrNew(['slug' => data_get($entry, 'slug')]);

                if ($model->cover_hash !== data_get($entry, 'cover_hash')) {
                    Storage::put(data_get($entry, 'cover_path'), File::get(data_get($entry, 'cover_file')));
                    $result['uploaded']++;
                }

                $model->fill(collect($entry)->except('cover_file')->all());

                if (! $model->exists) {
                    $result['created']++;
                } elseif ($model->isDirty()) {
                    $result['updated']++;
                }

                $model->save();
            }

            ChangelogEntry::query()
                ->whereNotIn('slug', $entries->pluck('slug')->all())
                ->get()
                ->each(function (ChangelogEntry $stale) use (&$result): void {
                    Storage::delete($stale->cover_path);
                    $stale->delete();
                    $result['deleted']++;
                });
        });

        return $result;
    }

    /**
     * @return array{slug: string, title: string, tag: ?string, body: string, cover_path: string, cover_hash: string, published_on: string, cover_file: string}
     */
    private static function parse(string $folder): array
    {
        $slug = basename($folder);
        $entryFile = "{$folder}/entry.md";
        $coverFile = "{$folder}/cover.png";

        if (! File::exists($entryFile)) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has no entry.md.");
        }

        if (! File::exists($coverFile)) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has no cover.png.");
        }

        try {
            $document = (new FrontMatterParser(new SymfonyYamlFrontMatterParser))->parse(File::get($entryFile));
        } catch (CommonMarkException $exception) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has invalid front matter: {$exception->getMessage()}");
        }

        $frontMatter = (array) ($document->getFrontMatter() ?? []);
        $date = data_get($frontMatter, 'date');

        $data = [
            'title' => data_get($frontMatter, 'title'),
            'tag' => data_get($frontMatter, 'tag'),
            'date' => is_int($date) ? CarbonImmutable::createFromTimestampUTC($date)->toDateString() : $date,
            'body' => trim($document->getContent()),
        ];

        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'tag' => ['nullable', Rule::enum(Tag::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'body' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] is invalid: ".implode(' ', $validator->errors()->all()));
        }

        return [
            'slug' => $slug,
            'title' => (string) data_get($data, 'title'),
            'tag' => data_get($data, 'tag'),
            'body' => (string) data_get($data, 'body'),
            'cover_path' => "changelog/{$slug}.png",
            'cover_hash' => (string) sha1_file($coverFile),
            'published_on' => (string) data_get($data, 'date'),
            'cover_file' => $coverFile,
        ];
    }
}
