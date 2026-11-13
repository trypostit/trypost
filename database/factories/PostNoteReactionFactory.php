<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\PostNote;
use App\Models\PostNoteReaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PostNoteReaction> */
class PostNoteReactionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_note_id' => PostNote::factory(),
            'user_id' => User::factory(),
            'emoji' => '👍',
        ];
    }
}
