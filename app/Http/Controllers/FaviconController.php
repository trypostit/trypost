<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Favicons\ResolveFavicon;
use Illuminate\Http\Response;

class FaviconController extends Controller
{
    /**
     * Always answers 200 (real icon or neutral globe). Third-party favicons can
     * be SVGs carrying `<script>`, hence the CSP, attachment disposition and
     * nosniff.
     */
    public function __invoke(string $domain): Response
    {
        $favicon = ResolveFavicon::execute($domain);

        return response($favicon['body'])->withHeaders([
            'Content-Type' => $favicon['contentType'],
            'Cache-Control' => 'public, max-age=2592000',
            'Content-Security-Policy' => "script-src 'none'",
            'Content-Disposition' => 'attachment',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
