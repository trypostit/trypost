<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\PostTemplate\BuildTemplatesPageProps;
use App\Actions\PostTemplate\CreatePostTemplate;
use App\Actions\PostTemplate\DeletePostTemplate;
use App\Actions\PostTemplate\DuplicatePostTemplate;
use App\Actions\PostTemplate\UpdatePostTemplate;
use App\Http\Controllers\App\Concerns\RespondsToTemplateWrites;
use App\Http\Requests\App\PostTemplate\DuplicateTemplateRequest;
use App\Http\Requests\App\PostTemplate\ListTemplatesRequest;
use App\Http\Requests\App\PostTemplate\StorePostTemplateRequest;
use App\Http\Requests\App\PostTemplate\UpdatePostTemplateRequest;
use App\Models\PostTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class PostTemplateController extends Controller
{
    use RespondsToTemplateWrites;

    public function index(ListTemplatesRequest $request): Response
    {
        $user = $request->user();

        return Inertia::render('create/Templates', BuildTemplatesPageProps::execute($request, $user->currentWorkspace, $user));
    }

    public function store(StorePostTemplateRequest $request): RedirectResponse|JsonResponse|HttpResponse
    {
        $template = CreatePostTemplate::execute($request->user()->currentWorkspace, $request->user(), $request->validated());

        return $this->respond($request, $template, HttpResponse::HTTP_CREATED, $template->visibility->value);
    }

    public function update(UpdatePostTemplateRequest $request, PostTemplate $postTemplate): RedirectResponse|JsonResponse|HttpResponse
    {
        $template = UpdatePostTemplate::execute($postTemplate, $request->user(), $request->validated());

        return $this->respond($request, $template, HttpResponse::HTTP_OK, $template->visibility->value);
    }

    public function destroy(Request $request, PostTemplate $postTemplate): RedirectResponse|JsonResponse|HttpResponse
    {
        $this->authorize('delete', $postTemplate);

        DeletePostTemplate::execute($postTemplate);

        return $this->respond($request, null, HttpResponse::HTTP_NO_CONTENT);
    }

    public function duplicate(DuplicateTemplateRequest $request, PostTemplate $postTemplate): RedirectResponse|JsonResponse|HttpResponse
    {
        $template = DuplicatePostTemplate::execute($postTemplate, $request->user(), $request->visibility());

        return $this->respond($request, $template, HttpResponse::HTTP_CREATED, $template->visibility->value);
    }
}
