<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\Asset\ImportWebdavRequest;
use App\Http\Resources\App\MediaResource;
use App\Services\WebdavService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

class WebdavController extends Controller
{
    public function browse(Request $request, WebdavService $webdav): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        abort_unless($webdav->enabled(), SymfonyResponse::HTTP_NOT_FOUND);

        try {
            return response()->json($webdav->list((string) $request->string('path')));
        } catch (RuntimeException $e) {
            abort(SymfonyResponse::HTTP_BAD_GATEWAY, $e->getMessage());
        }
    }

    public function store(ImportWebdavRequest $request, WebdavService $webdav): JsonResponse
    {
        $workspace = $request->user()->currentWorkspace;

        $this->authorize('createPost', $workspace);

        abort_unless($webdav->enabled(), SymfonyResponse::HTTP_NOT_FOUND);

        $imported = [];
        $failed = [];

        foreach ($request->validated('paths') as $path) {
            $temporary = null;

            try {
                $temporary = $webdav->download($path);

                $imported[] = $workspace->addMediaFromPath(
                    filePath: $temporary,
                    originalFilename: basename($webdav->normalize($path)),
                    collection: 'assets',
                );
            } catch (InvalidArgumentException|RuntimeException $e) {
                // A share holds everything, not just postable media, and one
                // unreadable file should not discard the rest of a selection.
                $failed[] = [
                    'path' => $path,
                    'name' => basename($webdav->normalize($path)),
                    'message' => $e->getMessage(),
                    'status' => $e instanceof InvalidArgumentException
                        ? SymfonyResponse::HTTP_UNPROCESSABLE_ENTITY
                        : SymfonyResponse::HTTP_BAD_GATEWAY,
                ];
            } finally {
                if ($temporary !== null && file_exists($temporary)) {
                    @unlink($temporary);
                }
            }
        }

        // Nothing came through: report it as the error it is, using the reason
        // of the first file so the message stays specific.
        if ($imported === [] && $failed !== []) {
            abort($failed[0]['status'], $failed[0]['message']);
        }

        return response()->json([
            'media' => MediaResource::collection($imported),
            'failed' => array_map(
                static fn (array $failure): array => [
                    'name' => $failure['name'],
                    'message' => $failure['message'],
                ],
                $failed,
            ),
        ]);
    }
}
