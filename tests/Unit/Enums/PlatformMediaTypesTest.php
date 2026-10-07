<?php

declare(strict_types=1);

use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;

test('a platform allows exactly the media kinds its content types accept', function (Platform $platform) {
    $accepted = collect(ContentType::forPlatform($platform))
        ->flatMap(fn (ContentType $type): array => array_keys(array_filter([
            'image' => $type->supportsImage(),
            'video' => $type->supportsVideo(),
            'document' => $type->supportsDocument(),
        ])))
        ->unique()
        ->sort()
        ->values()
        ->all();

    $allowed = collect($platform->allowedMediaTypes())->map->value->sort()->values()->all();

    expect($allowed)->toBe($accepted);
})->with(Platform::cases());
