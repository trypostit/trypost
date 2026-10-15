<?php

declare(strict_types=1);

namespace App\Dto;

use App\Enums\Changelog\Tag;
use Carbon\CarbonImmutable;
use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use League\CommonMark\Exception\CommonMarkException;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;

/**
 * One "What's New" entry folder (`<YYYY-MM-DD>-<slug>/entry.md` plus a 16:9
 * `cover.png`), read and validated. Anything invalid throws, naming the folder.
 */
final readonly class ChangelogEntryFile
{
    /**
     * @param  list<string>  $tags
     */
    private function __construct(
        public string $slug,
        public string $title,
        public array $tags,
        public string $body,
        public string $publishedOn,
    ) {}

    public static function fromFolder(string $folder): self
    {
        $slug = File::basename($folder);

        throw_if(File::missing("{$folder}/entry.md"), InvalidArgumentException::class, "Changelog entry [{$slug}] has no entry.md.");
        throw_if(File::missing("{$folder}/cover.png"), InvalidArgumentException::class, "Changelog entry [{$slug}] has no cover.png.");

        try {
            $document = (new FrontMatterParser(new SymfonyYamlFrontMatterParser))->parse(File::get("{$folder}/entry.md"));
        } catch (CommonMarkException $exception) {
            throw new InvalidArgumentException("Changelog entry [{$slug}] has invalid front matter: {$exception->getMessage()}");
        }

        $frontMatter = $document->getFrontMatter();
        $date = data_get($frontMatter, 'date');

        $validator = Validator::make([
            'slug' => $slug,
            'cover' => new HttpFile("{$folder}/cover.png"),
            'title' => data_get($frontMatter, 'title'),
            'tags' => transform(data_get($frontMatter, 'tags'), fn (mixed $tags): mixed => $tags, []),
            'date' => is_int($date) ? CarbonImmutable::createFromTimestampUTC($date)->toDateString() : $date,
            'body' => Str::trim($document->getContent()),
        ], [
            'slug' => ['alpha_dash:ascii', 'lowercase'],
            'cover' => [Rule::dimensions()->ratio(16 / 9)],
            'title' => ['required', 'string', 'max:255'],
            'tags' => ['list'],
            'tags.*' => ['required', 'distinct', Rule::enum(Tag::class)],
            'date' => ['required', 'date_format:Y-m-d', Rule::in([Str::substr($slug, 0, 10)])],
            'body' => ['required', 'string'],
        ], [
            'cover.dimensions' => 'The cover.png must be 16:9 (1200x675).',
            'date.in' => 'The folder name must start with the date (<YYYY-MM-DD>-<slug>).',
        ]);

        $errors = collect($validator->errors()->all())->implode(' ');

        throw_if($validator->fails(), InvalidArgumentException::class, "Changelog entry [{$slug}] is invalid: {$errors}");

        $entry = $validator->validated();

        return new self(
            slug: $slug,
            title: data_get($entry, 'title'),
            tags: data_get($entry, 'tags'),
            body: data_get($entry, 'body'),
            publishedOn: data_get($entry, 'date'),
        );
    }

    /**
     * @return array{slug: string, title: string, tags: list<string>, body: string, published_on: string}
     */
    public function attributes(): array
    {
        return [
            'slug' => $this->slug,
            'title' => $this->title,
            'tags' => $this->tags,
            'body' => $this->body,
            'published_on' => $this->publishedOn,
        ];
    }
}
