<?php

declare(strict_types=1);

use App\Mcp\Servers\TryPostServer;
use Laravel\Mcp\Server\Attributes\Icon;

it('exposes the trypost logo as the mcp server icon', function () {
    $icons = (new ReflectionClass(TryPostServer::class))->getAttributes(Icon::class);

    expect($icons)->toHaveCount(1);

    $icon = $icons[0]->newInstance();

    expect($icon->src)->toBe('images/trypost/icon.png')
        ->and($icon->mimeType)->toBe('image/png')
        ->and($icon->theme)->toBeNull();
});
