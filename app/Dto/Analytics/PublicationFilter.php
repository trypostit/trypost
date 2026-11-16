<?php

declare(strict_types=1);

namespace App\Dto\Analytics;

use App\Enums\Analytics\PublicationContentType;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Post;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\ValidatedInput;

/**
 * Narrows publications by the labels of their TryPost post and, for one channel, by post type.
 * Without a platform (the workspace Insights, across every network) it narrows by label only.
 *
 * Expects the publication query aliased as `publication`, left-joined to its post as `destination`.
 * A publication with a post platform takes that post platform's content type; one without (published
 * outside TryPost and never imported) is typed through ContentType::fromPublication(), and an unknown
 * publication type matches no post type. A publication without a post carries no labels, so it only
 * matches the untagged option.
 */
final readonly class PublicationFilter
{
    /**
     * @param  list<string>  $labelIds  Label UUIDs; one deleted or of another workspace matches no post, since only this workspace's posts carry its labels.
     * @param  list<ContentType>  $contentTypes  Content types of $platform; none without one.
     */
    public function __construct(
        public ?Platform $platform,
        public array $labelIds = [],
        public bool $untagged = false,
        public array $contentTypes = [],
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(Platform $platform, array $validated): self
    {
        return new self(
            $platform,
            array_values((array) data_get($validated, 'labels', [])),
            filter_var(data_get($validated, 'untagged', false), FILTER_VALIDATE_BOOLEAN),
            array_values(array_map(
                fn (string $type): ContentType => ContentType::from($type),
                (array) data_get($validated, 'types', []),
            )),
        );
    }

    /**
     * Narrows the publications of every network by label, from the validated `labels` and `untagged`.
     */
    public static function labelsFrom(ValidatedInput $validated): self
    {
        return new self(null, $validated->array('labels'), $validated->boolean('untagged'));
    }

    /**
     * @return list<string>
     */
    public static function typesFor(Platform $platform): array
    {
        return array_values(array_map(fn (ContentType $type): string => $type->value, ContentType::forPlatform($platform)));
    }

    public function apply(Builder $query): Builder
    {
        return $query
            ->when($this->labelIds !== [] || $this->untagged, fn (Builder $labelled): Builder => $labelled->where(fn (Builder $any): Builder => $any
                ->when($this->labelIds !== [], fn (Builder $tagged): Builder => $tagged->whereExists($this->posts()
                    ->whereHas('labels', fn (EloquentBuilder $labels): EloquentBuilder => $labels->whereKey($this->labelIds))
                    ->toBase()))
                ->when($this->untagged, fn (Builder $none): Builder => $none->orWhereNotExists($this->posts()->has('labels')->toBase()))))
            ->when($this->contentTypes !== [], fn (Builder $typed): Builder => $typed->where(fn (Builder $any): Builder => $any
                ->whereIn('destination.content_type', $this->values())
                ->orWhere(fn (Builder $external): Builder => $external
                    ->whereNull('destination.id')
                    ->whereIn('publication.content_type', $this->publicationTypes()))));
    }

    /**
     * @return EloquentBuilder<Post>
     */
    private function posts(): EloquentBuilder
    {
        return Post::query()->select('posts.id')->whereColumn('posts.id', 'destination.id');
    }

    /**
     * @return list<string>
     */
    private function values(): array
    {
        return array_map(fn (ContentType $type): string => $type->value, $this->contentTypes);
    }

    /**
     * @return list<string>
     */
    private function publicationTypes(): array
    {
        if (blank($this->platform)) {
            return [];
        }

        return array_values(array_map(
            fn (PublicationContentType $type): string => $type->value,
            array_filter(
                PublicationContentType::cases(),
                fn (PublicationContentType $type): bool => $type !== PublicationContentType::Unknown
                    && in_array(ContentType::fromPublication($this->platform, $type), $this->contentTypes, true),
            ),
        ));
    }
}
