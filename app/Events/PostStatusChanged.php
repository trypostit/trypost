<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Post;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class PostStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public Post $post) {}
}
