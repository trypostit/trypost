<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\UpdatePostRecurrence;
use App\Http\Requests\App\Post\UpdatePostRecurrenceRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;

class PostRecurrenceController extends Controller
{
    public function update(UpdatePostRecurrenceRequest $request, Post $post): RedirectResponse
    {
        UpdatePostRecurrence::execute($post, $request->validated());

        return back();
    }

    public function destroy(Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        UpdatePostRecurrence::execute($post, null);

        return back();
    }
}
