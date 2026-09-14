<?php

use App\Data\DwellWindow;
use Carbon\CarbonImmutable;

$at = fn (string $time): CarbonImmutable => CarbonImmutable::parse("2026-09-14 {$time}", 'UTC');

it('gives a party the stay its size earns', function (int $partySize, int $minutes) {
    expect(DwellWindow::minutesForParty($partySize))->toBe($minutes);
})->with([
    'a couple' => [2, 75],
    'a single diner takes the same tier' => [1, 75],
    'four' => [4, 90],
    'five rounds up to the six tier' => [5, 105],
    'six' => [6, 105],
    // Key 0 in the config is a sentinel for "bigger than every tier", not the
    // first entry of the table. Reading it as the latter would return 75.
    'a large party falls through to the default' => [9, 120],
]);

it('rounds up to whole slots, because half a slot cannot be resold', function () use ($at) {
    // 75 minutes is two and a half 30-minute slots.
    expect(DwellWindow::of($at('13:00'), 75)->slotCount())->toBe(3)
        ->and(DwellWindow::of($at('13:00'), 90)->slotCount())->toBe(3)
        ->and(DwellWindow::of($at('13:00'), 91)->slotCount())->toBe(4);
});

it('lists every slot the stay covers, in order', function () use ($at) {
    $window = DwellWindow::forParty($at('13:00'), 4);

    expect($window->slots)->toHaveCount(3)
        ->and(array_map(fn (CarbonImmutable $s): string => $s->format('H:i'), $window->slots))
        ->toBe(['13:00', '13:30', '14:00'])
        ->and($window->endsAt->format('H:i'))->toBe('14:30');
});

it('always occupies at least one slot', function () use ($at) {
    expect(DwellWindow::of($at('13:00'), 0)->slotCount())->toBe(1);
});

it('keeps the duration it was built with rather than recomputing it', function () use ($at) {
    // A booking made under an older configuration must not silently change
    // length because the config did.
    $window = DwellWindow::of($at('13:00'), 45);

    expect($window->durationMinutes)->toBe(45)
        ->and($window->slotCount())->toBe(2);
});
