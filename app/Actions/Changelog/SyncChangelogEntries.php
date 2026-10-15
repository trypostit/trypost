<?php

declare(strict_types=1);

namespace App\Actions\Changelog;

use App\Enums\Changelog\Tag;
use App\Models\ChangelogEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;

/**
 * Mirrors the Markdown entries (`changelog/<slug>.md`) into `changelog_entries`.
 * Each entry needs a 16:9 cover at `public/images/changelog/<slug>.png`. Every
 * entry is validated before anything is written, and a row whose file is gone
 * is deleted.
 */
class SyncChangelogEntries
{
    /**
     * @return array{created: int, updated: int, deleted: int}
     */
    public static function execute(string $entriesDirectory, string $coversDirectory): array
    {
        $entries = collect(File::isDirectory($entriesDirectory) ? File::glob("{$entriesDirectory}/*.md") : [])
            ->sort()
            ->map(fn (string $file): array => self::parse($file, $coversDirectory))
            ->values();

        $result = ['created' => 0, 'updated' => 0, 'deleted' => 0];

        DB::transaction(function () use ($entries, &$result): void {
            foreach ($entries as $entry) {
                $model = ChangelogEntry::firstOrNew(['slug' => data_get($entry, 'slug')])->fill($entry);

                if (! $model->exists) {
                    $result['created']++;
                } elseif ($model->isDirty()) {
                    $result['updated']++;
                }

                $model->save();
            }

            $result['deleted'] = ChangelogEntry::query()
                ->whereNotIn('slug', $entries->pluck('slug')->all())
                ->get()
                ->each->delete()
                ->count();
        });

        return $result;
    }

    /**
     * @return array{slug: string, title: string, tags: list<string>, body: string, published_on: string}
     */
    private static function parse(string $file, string $coversDirectory): array
    {
        $slug = File::name($file);
        $coverFile = "{$coversDirectory}/{$slug}.png";

        if (! File::exists($coverFile)) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has no cover at {$coverFile}.");
        }

        [$coverWidth, $coverHeight] = getimagesize($coverFile) ?: [0, 0];

        if ($coverWidth === 0 || $coverWidth * 9 !== $coverHeight * 16) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] needs a 16:9 cover (1200x675), got {$coverWidth}x{$coverHeight}.");
        }

        try {
            $document = (new FrontMatterParser(new SymfonyYamlFrontMatterParser))->parse(File::get($file));
        } catch (CommonMarkException $exception) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has invalid front matter: {$exception->getMessage()}");
        }

        $frontMatter = (array) ($document->getFrontMatter() ?? []);
        $date = data_get($frontMatter, 'date');

        $data = [
            'title' => data_get($frontMatter, 'title'),
            'tags' => data_get($frontMatter, 'tags', []),
            'date' => is_int($date) ? CarbonImmutable::createFromTimestampUTC($date)->toDateString() : $date,
            'body' => trim($document->getContent()),
        ];

        $validator = Validator::make($data, [
            'title' => ['required', 'string', 'max:255'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['distinct', Rule::enum(Tag::class)],
            'date' => ['required', 'date_format:Y-m-d'],
            'body' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] is invalid: ".implode(' ', $validator->errors()->all()));
        }

        return [
            'slug' => $slug,
            'title' => (string) data_get($data, 'title'),
            'tags' => array_values((array) data_get($data, 'tags', [])),
            'body' => (string) data_get($data, 'body'),
            'published_on' => (string) data_get($data, 'date'),
        ];
    }
}
