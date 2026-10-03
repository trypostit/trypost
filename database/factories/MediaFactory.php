<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\Media\Type as MediaType;
use App\Models\Idea;
use App\Models\Media;
use App\Models\Post;
use App\Models\RssFeedItem;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mediable_type' => (new Workspace)->getMorphClass(),
            'mediable_id' => Workspace::factory(),
            'collection' => 'default',
            'type' => MediaType::Image,
            'path' => 'media/'.now()->format('Y-m').'/'.$this->faker->uuid().'.jpg',
            'original_filename' => $this->faker->word().'.jpg',
            'mime_type' => 'image/jpeg',
            'size' => $this->faker->numberBetween(10000, 5000000),
            'order' => 0,
            'meta' => [
                'width' => 1920,
                'height' => 1080,
            ],
        ];
    }

    public function temporaryUpload(Workspace $workspace): static
    {
        return $this->ownedByWorkspace($workspace)->state(fn (array $attributes) => [
            'collection' => Media::COLLECTION_UPLOADS,
            'upload_token' => (string) Str::uuid(),
        ]);
    }

    public function libraryAsset(Workspace $workspace): static
    {
        return $this->ownedByWorkspace($workspace)->state(fn (array $attributes) => [
            'collection' => Media::LIBRARY_COLLECTION,
        ]);
    }

    public function ownedByPost(Post $post): static
    {
        return $this->ownedBy('post_id', $post->id, $post->workspace_id);
    }

    public function ownedByIdea(Idea $idea): static
    {
        return $this->ownedBy('idea_id', $idea->id, $idea->workspace_id);
    }

    public function ownedByFeedItem(RssFeedItem $item): static
    {
        return $this->ownedBy('rss_feed_item_id', $item->id, $item->feed->workspace_id);
    }

    /**
     * Writes a placeholder file at the row's path on the default disk, for
     * tests that copy or read the file. Use it with `Storage::fake()`.
     */
    public function stored(): static
    {
        return $this->afterCreating(fn (Media $media) => Storage::put($media->path, 'media bytes'));
    }

    public function video(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MediaType::Video,
            'path' => 'media/'.now()->format('Y-m').'/'.$this->faker->uuid().'.mp4',
            'original_filename' => $this->faker->word().'.mp4',
            'mime_type' => 'video/mp4',
            'meta' => [
                'duration' => $this->faker->numberBetween(10, 300),
            ],
        ]);
    }

    public function document(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => MediaType::Document,
            'path' => 'media/'.now()->format('Y-m').'/'.$this->faker->uuid().'.pdf',
            'original_filename' => $this->faker->word().'.pdf',
            'mime_type' => 'application/pdf',
            'meta' => [],
        ]);
    }

    public function logo(): static
    {
        return $this->state(fn (array $attributes) => [
            'collection' => 'logo',
        ]);
    }

    public function avatar(): static
    {
        return $this->state(fn (array $attributes) => [
            'collection' => 'avatar',
        ]);
    }

    private function ownedByWorkspace(Workspace $workspace): static
    {
        return $this->state(fn (array $attributes) => [
            'mediable_type' => $workspace->getMorphClass(),
            'mediable_id' => $workspace->id,
        ]);
    }

    private function ownedBy(string $ownerColumn, string $ownerId, string $workspaceId): static
    {
        return $this->state(fn (array $attributes) => [
            'mediable_type' => null,
            'mediable_id' => null,
            'workspace_id' => $workspaceId,
            'collection' => Media::COLLECTION_MEDIA,
            $ownerColumn => $ownerId,
        ]);
    }
}
