<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Actions\Admin\ListFailedPosts;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Post\IndexFailedPostRequest;
use App\Http\Resources\Admin\AdminFailedPostResource;
use App\Support\Admin\AdminNetworks;
use Inertia\Inertia;
use Inertia\Response;

class FailedPostController extends Controller
{
    public function index(IndexFailedPostRequest $request): Response
    {
        $posts = ListFailedPosts::execute($request->platforms(), $request->category())->paginate((int) config('app.pagination.default'));

        return Inertia::render('admin/posts/Failed', [
            'posts' => Inertia::scroll(fn () => AdminFailedPostResource::collection($posts)),
            'networks' => AdminNetworks::options(),
            'categories' => ListFailedPosts::categoryCounts($request->platforms()),
            'filters' => [
                'networks' => $request->networks(),
                'category' => $request->category()?->value,
            ],
        ]);
    }
}
