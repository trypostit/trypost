<?php

declare(strict_types=1);

use App\Enums\UserWorkspace\Role;
use App\Models\ContentRating;
use App\Models\User;
use App\Models\Workspace;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->workspace = Workspace::factory()->create(['user_id' => $this->user->id]);
    $this->workspace->members()->attach($this->user->id, ['role' => Role::Member->value]);
    $this->user->update(['current_workspace_id' => $this->workspace->id]);
});

test('a rating is recorded for the current workspace', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.content-ratings.store'), ['rating' => 4])
        ->assertNoContent();

    $rating = ContentRating::query()->first();

    expect($rating)->not->toBeNull()
        ->and($rating->rating)->toBe(4)
        ->and($rating->workspace_id)->toBe($this->workspace->id);
});

test('rating requires authentication', function () {
    $this->postJson(route('app.content-ratings.store'), ['rating' => 4])
        ->assertUnauthorized();
});

test('rating must be between 1 and 5', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.content-ratings.store'), ['rating' => 6])
        ->assertJsonValidationErrors('rating');
});

test('rejects a rateable_type outside the morph map, which would crash on resolve', function () {
    $this->actingAs($this->user)
        ->postJson(route('app.content-ratings.store'), [
            'rating' => 4,
            'rateable_type' => 'foo',
            'rateable_id' => $this->workspace->id,
        ])
        ->assertJsonValidationErrors('rateable_type');
});

test('a rating tied to an item is idempotent: rating again updates instead of duplicating', function () {
    $rate = fn (int $n) => $this->actingAs($this->user)->postJson(route('app.content-ratings.store'), [
        'rating' => $n,
        'rateable_type' => $this->workspace->getMorphClass(),
        'rateable_id' => $this->workspace->id,
    ]);

    $rate(3)->assertNoContent();
    $rate(5)->assertNoContent();

    expect(ContentRating::query()->count())->toBe(1)
        ->and(ContentRating::query()->first()->rating)->toBe(5);
});
