<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\Post\LinkPreviewRequest;
use App\Services\Social\LinkCard\LinkCardFetcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class LinkPreviewController extends Controller
{
    public function __invoke(LinkPreviewRequest $request, LinkCardFetcher $fetcher): JsonResponse|Response
    {
        $card = $fetcher->fetch($request->validated('url'));

        if ($card === null) {
            return response()->noContent();
        }

        return response()->json($card->toArray());
    }
}
