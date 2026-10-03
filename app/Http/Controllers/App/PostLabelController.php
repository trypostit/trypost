<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\SyncPostLabels;
use App\Http\Requests\App\Post\UpdatePostLabelsRequest;
use App\Models\Post;
use Illuminate\Http\RedirectResponse;

class PostLabelController extends Controller
{
    public function update(UpdatePostLabelsRequest $request, Post $post): RedirectResponse
    {
        $this->authorize('update', $post);

        SyncPostLabels::execute($post, $request->validated());

        return back();
    }
}
