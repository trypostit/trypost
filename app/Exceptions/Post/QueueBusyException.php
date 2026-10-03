<?php

declare(strict_types=1);

namespace App\Exceptions\Post;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class QueueBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(__('posts.errors.queue_busy'));
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage()], Response::HTTP_CONFLICT);
        }

        return back()->withErrors(['queue' => $this->getMessage()]);
    }
}
