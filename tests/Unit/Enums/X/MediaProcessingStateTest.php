<?php

declare(strict_types=1);

use App\Enums\X\MediaProcessingState;

test('x media processing state matches the media upload status values', function () {
    expect(array_map(fn (MediaProcessingState $state): string => $state->value, MediaProcessingState::cases()))
        ->toBe(['pending', 'in_progress', 'succeeded', 'failed']);
});

test('only pending and in progress count as still processing', function (MediaProcessingState $state, bool $isProcessing) {
    expect($state->isProcessing())->toBe($isProcessing);
})->with([
    [MediaProcessingState::Pending, true],
    [MediaProcessingState::InProgress, true],
    [MediaProcessingState::Succeeded, false],
    [MediaProcessingState::Failed, false],
]);
