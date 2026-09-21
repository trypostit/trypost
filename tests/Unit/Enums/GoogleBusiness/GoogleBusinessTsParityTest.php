<?php

declare(strict_types=1);

use App\Enums\GoogleBusiness\CtaAction;
use App\Enums\GoogleBusiness\DeprecatedCtaAction;
use App\Enums\GoogleBusiness\TopicType;

/**
 * `resources/js/types/google-business.ts` mirrors the PHP enums so the editor
 * cannot drift. Values are compared as sets — TS object key order is not API order.
 */
test('typescript google business topic and cta values match the php enums', function () {
    $source = file_get_contents(resource_path('js/types/google-business.ts'));

    expect($source)->toBeString();

    $quotedValues = function (string $name) use ($source): array {
        expect($source)->toMatch("/export const {$name} = \\{([^}]+)\\}/s");
        preg_match("/export const {$name} = \\{([^}]+)\\}/s", $source, $matches);
        preg_match_all("/'([A-Z_]+)'/", $matches[1], $values);

        return $values[1];
    };

    expect($quotedValues('GoogleBusinessTopicType'))->toEqualCanonicalizing(TopicType::values())
        ->and($quotedValues('GoogleBusinessCtaAction'))->toEqualCanonicalizing(CtaAction::values())
        ->and($quotedValues('GoogleBusinessDeprecatedCtaAction'))->toEqualCanonicalizing([
            DeprecatedCtaAction::GetOffer->value,
        ]);
});
