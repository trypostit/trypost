<?php

declare(strict_types=1);

use App\Enums\Tour\Tour;
use Illuminate\Support\Facades\Lang;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Runs `tests/fixtures/tours-harness.ts` over `resources/js/lib/tours.ts`.
 *
 * @param  list<array{steps: list<string>, onPage: list<string>, current: string}>  $cases
 * @return array{tours: array<string, list<string>>, cases: list<array{shown: list<string>, first: ?string, after: ?string, before: ?string}>}
 */
function runToursHarness(array $cases = []): array
{
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        test()->markTestSkipped('node is unavailable');
    }

    $process = (new Process([
        $node,
        base_path('tests/fixtures/tours-harness.ts'),
        resource_path('js/lib/tours.ts'),
        json_encode($cases),
    ]))->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

test('a tour opens on its first step on the page and walks its steps in its own order, skipping the ones not on the page', function () {
    $cases = [
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['three', 'one', 'two'], 'current' => 'one'],
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['one', 'two', 'three'], 'current' => 'two'],
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['one', 'two', 'three'], 'current' => 'three'],
        ['steps' => ['one', 'two', 'three'], 'onPage' => ['one', 'three'], 'current' => 'one'],
        ['steps' => ['one'], 'onPage' => ['one'], 'current' => 'one'],
        ['steps' => ['one', 'two'], 'onPage' => ['two'], 'current' => 'two'],
        ['steps' => ['one', 'two'], 'onPage' => [], 'current' => 'one'],
    ];

    expect(runToursHarness($cases)['cases'])->toBe([
        ['shown' => ['one', 'two', 'three'], 'first' => 'one', 'after' => 'two', 'before' => null],
        ['shown' => ['one', 'two', 'three'], 'first' => 'one', 'after' => 'three', 'before' => 'one'],
        ['shown' => ['one', 'two', 'three'], 'first' => 'one', 'after' => null, 'before' => 'two'],
        ['shown' => ['one', 'three'], 'first' => 'one', 'after' => 'three', 'before' => null],
        ['shown' => ['one'], 'first' => 'one', 'after' => null, 'before' => null],
        ['shown' => ['two'], 'first' => 'two', 'after' => null, 'before' => null],
        ['shown' => [], 'first' => null, 'after' => null, 'before' => null],
    ]);
});

test('the tours of the frontend are the tours of the backend, and each of their steps has its copy', function () {
    $tours = runToursHarness()['tours'];
    $names = array_map(fn (Tour $tour): string => $tour->value, Tour::cases());

    expect(array_keys($tours))->toEqualCanonicalizing($names);

    foreach ($tours as $tour => $steps) {
        expect(array_keys(Lang::get("tours.{$tour}.steps", [], 'en')))->toBe($steps);

        foreach ($steps as $step) {
            expect(Lang::has("tours.{$tour}.steps.{$step}.title", 'en', false))->toBeTrue()
                ->and(Lang::has("tours.{$tour}.steps.{$step}.description", 'en', false))->toBeTrue();
        }
    }
});
