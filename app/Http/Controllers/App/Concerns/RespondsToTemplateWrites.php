<?php

declare(strict_types=1);

namespace App\Http\Controllers\App\Concerns;

use App\Http\Resources\App\PostTemplateResource;
use App\Models\PostTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

trait RespondsToTemplateWrites
{
    private function respond(Request $request, ?PostTemplate $template, int $status, ?string $redirectView = null): RedirectResponse|JsonResponse|Response
    {
        if (! $request->header('X-Inertia') && $request->expectsJson()) {
            return $template === null
                ? response()->noContent()
                : PostTemplateResource::make($template->load('user:id,name'))->response()->setStatusCode($status);
        }

        return $redirectView === null
            ? back()
            : redirect()->route('app.create.templates.index', ['view' => $redirectView]);
    }
}
