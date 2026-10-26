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
     * Keeps the slug of an available `?offer=` in the session until the visitor is signed in,
     * then claims it for their account. Signup (email, Google, GitHub) and
     * login all land on an authenticated request, so none of them handles it.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $slug = $request->query('offer');

        if (is_string($slug) && filled($slug) && Offer::available()->where('slug', $slug)->exists()) {
            $request->session()->put(self::SESSION_KEY, $slug);
        }

        $user = $request->user();

        if (filled($user) && $request->session()->has(self::SESSION_KEY)) {
            $pendingSlug = $request->session()->pull(self::SESSION_KEY);

            if ($user->isAccountOwner() && filled($user->account)) {
                ClaimOffer::execute($user->account, $pendingSlug);
            }
        }

        return $next($request);
    }
}
