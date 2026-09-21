<?php

declare(strict_types=1);

use App\Enums\GoogleBusiness\CtaAction;
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
        ->and($source)->toContain('GOOGLE_BUSINESS_EVENT_TITLE_MAX = '.TopicType::TITLE_MAX_LENGTH)
        ->and($source)->not->toContain('GET_OFFER')
        ->and($source)->not->toContain('DeprecatedCta');
});

test('typescript event range helper keeps the same-day time comparison the php rule uses', function () {
    $source = file_get_contents(resource_path('js/lib/googleBusiness.ts'));

    expect($source)->toBeString()
        ->and($source)->toContain('endDate < startDate')
        ->and($source)->toContain('endTime < startTime');
});

test('the publishing overlay treats pending_review as settled enough to hide the spinner', function () {
    $source = file_get_contents(resource_path('js/composables/usePostStatus.ts'));

    expect($source)->toBeString();
    expect($source)->toMatch('/const IN_FLIGHT_PLATFORM_STATUSES[\s\S]*PostPlatformStatus\.Retrying,/');
    expect($source)->not->toMatch('/IN_FLIGHT_PLATFORM_STATUSES[\s\S]*PendingReview/');
});
