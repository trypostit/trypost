<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Enums\Media\Source;
use App\Http\Resources\App\GooglePhotosSessionResource;
use App\Services\Media\Sources\GooglePhotosPicker;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Reports whether the user picked in a Google Photos Picker session that
 * GoogleMediaController opened, or discards it. The token stays server-side.
 */
class GooglePhotosSessionController extends Controller implements HasMiddleware
{
    public const string SESSION_ROUTE_PATTERN = '[A-Za-z0-9_-][A-Za-z0-9._-]*';

    public function __construct(private readonly GooglePhotosPicker $picker) {}

    /**
     * @return list<Closure>
     */
    public static function middleware(): array
    {
        return [
            function (Request $request, Closure $next): mixed {
                abort_unless(Source::GooglePhotos->isEnabled(), Response::HTTP_NOT_FOUND);

                return $next($request);
            },
        ];
    }

    public function show(Request $request, string $session): GooglePhotosSessionResource
    {
        $this->authorize('createPost', $request->user()->currentWorkspace);

        $accessToken = GooglePhotosPicker::token($request->user()->id, $session);

        abort_if($accessToken === null, Response::HTTP_NOT_FOUND);

        try {
            return new GooglePhotosSessionResource($this->picker->session($session, $accessToken));
        } catch (Throwable) {
            abort(Response::HTTP_BAD_GATEWAY);
        }
    }

    public function destroy(Request $request, string $session): Response
    {
        $this->authorize('createPost', $request->user()->currentWorkspace);

        $accessToken = GooglePhotosPicker::token($request->user()->id, $session);

        abort_if($accessToken === null, Response::HTTP_NOT_FOUND);

        GooglePhotosPicker::forget($request->user()->id, $session);
        rescue(fn () => $this->picker->deleteSession($session, $accessToken), report: false);

        return response()->noContent();
    }
}
