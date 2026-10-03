<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\PostTemplate\DuplicateLibraryTemplate;
use App\Http\Controllers\App\Concerns\RespondsToTemplateWrites;
use App\Http\Requests\App\PostTemplate\DuplicateTemplateRequest;
use App\Support\TemplateLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class LibraryTemplateController extends Controller
{
    use RespondsToTemplateWrites;

    public function duplicate(DuplicateTemplateRequest $request, string $key): RedirectResponse|JsonResponse|HttpResponse
    {
        $user = $request->user();

        $template = DuplicateLibraryTemplate::execute(TemplateLibrary::find($key), $user->currentWorkspace, $user, $request->visibility());

        return $this->respond($request, $template, HttpResponse::HTTP_CREATED, $template->visibility->value);
    }
}
