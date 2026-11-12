<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Actions\PostNote\ListPostNoteAudience;
use App\Http\Resources\Api\PostNoteMentionableResource;
use App\Models\Post;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class PostNoteMentionableController extends Controller
{
    public function index(Request $request, Post $post): AnonymousResourceCollection
    {
        $this->authorize('view', $post);
        $this->authorize('createPost', $post->workspace);

        return PostNoteMentionableResource::collection(ListPostNoteAudience::execute($post, except: $request->user()));
    }
}
