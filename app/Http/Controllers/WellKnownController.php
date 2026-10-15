<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class WellKnownController extends Controller
{
    public function __invoke(string $file): Response
    {
        $content = config()->collection('trypost.well_known')->get($file);

        abort_unless(filled($content), Response::HTTP_NOT_FOUND);

        return response($content, Response::HTTP_OK, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
