<?php

declare(strict_types=1);

namespace App\Http\Middleware\App;

use App\Support\LocaleResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = LocaleResolver::resolve($request);

        App::setLocale($locale->value);
        View::share('htmlDir', $locale->direction());

        return $next($request);
    }
}
