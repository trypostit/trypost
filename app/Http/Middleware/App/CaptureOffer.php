<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Actions\Offer\ClaimOffer;
use App\Models\Offer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CaptureOffer
{
    public const string SESSION_KEY = 'pending_offer';

    /**
     * Claims an `?offer=` for a signed-in owner at once. A guest's slug waits
     * in the session until they sign in: signup (email, Google, GitHub) and
     * login all land on an authenticated request, so none of them handles it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->query('offer');
        $slug = is_string($slug) && filled($slug) ? $slug : null;
        $user = $request->user();

        if (blank($user)) {
            if (filled($slug) && filled(Offer::findAvailable($slug))) {
                $request->session()->put(self::SESSION_KEY, $slug);
            }

            return $next($request);
        }

        $pendingSlug = $request->session()->pull(self::SESSION_KEY);
        $slug ??= $pendingSlug;

        if (filled($slug) && $user->isAccountOwner() && filled($user->account)) {
            ClaimOffer::execute($user->account, $slug);
        }

        return $next($request);
    }
}
