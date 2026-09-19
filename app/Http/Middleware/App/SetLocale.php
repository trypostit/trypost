<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Enums\User\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Auth::user()?->locale ?? Locale::DEFAULT;

        App::setLocale($locale->value);
        View::share('htmlDir', $locale->direction());

        return $next($request);
    }
}
