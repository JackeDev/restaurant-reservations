<?php

namespace App\Mcp\Tools;

use App\Contracts\SlotAllocator;
use App\Data\DwellWindow;
use App\Mcp\Concerns\ReportsFailures;
use App\Services\OpeningHours;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Throwable;

#[Name('check_availability')]
#[Title('Check availability')]
#[Description(<<<'TEXT'
    Lists the times a party can be seated on a given date, along with the
    restaurant's timezone, booking interval and opening hours.

    When nothing is available the result says why, so the answer to the customer
    can be specific: the restaurant is closed that day, the date has passed, or
    every open time is already full.

    Useful before booking, or to answer "when could we come instead?".
    TEXT)]
#[IsReadOnly]
#[IsIdempotent]
class CheckAvailability extends Tool
{
    use ReportsFailures;

    /**
     * Local midnight: the anchor every calculation about a calendar date starts
     * from, since the slot grid and the opening-hours weekday are both defined
     * against the restaurant's own day.
     */
    private const LOCAL_DAY_START = '00:00';

    public function __construct(
        private readonly SlotAllocator $allocator,
        private readonly OpeningHours $openingHours,
        private readonly RestaurantClock $clock,
    ) {}

    /**
     * Stamped with the restaurant's current local time, resolved per request.
     * See MakeReservation::description() for why.
     */
    public function description(): string
    {
        return parent::description()."\n\n".sprintf(
            'It is currently %s. Resolve "today", "tomorrow" and similar against that, not against your own clock.',
            $this->clock->describeNow(),
        );
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->format('date')->required()
                ->description('Date to check in the restaurant\'s timezone, YYYY-MM-DD — which may not be the date where you are.'),

            'party_size' => $schema->integer()->min(1)->max(20)->required()
                ->description('How many guests need seating.'),
        ];
    }

    /**
     * @return array<string, Type>
     */
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'date' => $schema->string()->required(),
            'timezone' => $schema->string()->required(),

            // The authoritative answer to "what day is it?", so an agent whose
            // own clock sits in another timezone never has to guess.
            'today' => $schema->string()->required()
                ->description('The current date at the restaurant. Resolve "tomorrow" and similar against this.'),

            'date_is' => $schema->string()->required()
                ->description('The date you asked about, in words relative to the restaurant\'s today — for example "Monday, tomorrow". Check it against what the customer actually said before going further.'),
            'slot_minutes' => $schema->integer()->required()
                ->description('Bookings start every this many minutes.'),
            'opening_hours' => $schema->array()->items($schema->string())->required()
                ->description('The hours the restaurant opens on that date, whether or not anything is free. Empty means closed all day.'),
            'available' => $schema->array()->required()->items(
                $schema->object(fn (JsonSchema $schema): array => [
                    'time' => $schema->string()->required(),
                    'seats_available' => $schema->integer()->required(),
                ])
            )->description('Only times that can seat the whole party for their full stay.'),

            // Omitted when there is something to offer: an empty list needs
            // explaining, a full one does not.
            'reason' => $schema->string()
                ->description('Why no times are available. Present only when the list is empty.'),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        // Left outside the try: the package renders a validation failure as a
        // readable per-field message, which beats a correlation id here.
        ['date' => $date, 'party_size' => $partySize] = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'party_size' => ['required', 'integer', 'between:1,20'],
        ]);

        try {
            return $this->availabilityOn($date, $partySize);
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable $e) {
            return $this->failed($e);
        }
    }

    private function availabilityOn(string $date, int $partySize): ResponseFactory
    {
        $slots = $this->bookableSlotsOn($date);

        if ($slots === []) {
            return $this->respond($date, [], $this->reasonForNoSlots($date));
        }

        /*
         * Each candidate is judged on its whole dwell window, not just its
         * first slot: a time whose window overlaps a full slot cannot actually
         * seat the party, and offering it would be worse than offering nothing.
         * One round trip covers the union of every window.
         */
        $windows = [];
        $union = [];

        foreach ($slots as $slot) {
            $window = DwellWindow::forParty($slot, $partySize);
            $windows[] = [$slot, $window];

            foreach ($window->slots as $windowSlot) {
                $union[$windowSlot->format('Y-m-d\TH:i')] = $windowSlot;
            }
        }

        $remaining = $this->allocator->remainingFor(array_values($union));

        $available = [];

        foreach ($windows as [$slot, $window]) {
            $seats = PHP_INT_MAX;

            foreach ($window->slots as $windowSlot) {
                $seats = min($seats, $remaining[$windowSlot->format('Y-m-d\TH:i')] ?? 0);
            }

            if ($seats >= $partySize) {
                $available[] = [
                    'time' => $this->clock->localTime($slot),
                    'seats_available' => $seats,
                ];
            }
        }

        return $this->respond($date, $available, $available === []
            ? "The restaurant is open on {$date}, but every time is already fully booked for a party of {$partySize}."
            : null);
    }

    /**
     * Build the result. Opening hours are reported for the requested date
     * whatever the outcome: "we are closed that day" and "we are open but full"
     * are different answers to the customer, and an empty list alone cannot
     * tell them apart.
     *
     * @param  list<array{time: string, seats_available: int}>  $available
     */
    private function respond(string $date, array $available, ?string $reason): ResponseFactory
    {
        $startOfDay = $this->clock->parseLocal($date, self::LOCAL_DAY_START);

        $payload = [
            'date' => $date,
            'timezone' => $this->clock->timezone(),
            'today' => $this->clock->localDate($this->clock->now()),
            'date_is' => $this->clock->describeDate($startOfDay),
            'slot_minutes' => (int) config('restaurant.slot_minutes'),
            'opening_hours' => $this->openingHours->describeFor($startOfDay),
            'available' => $available,
        ];

        if ($reason !== null) {
            $payload['reason'] = $reason;
        }

        return Response::structured($payload);
    }

    /**
     * Why a date produced no bookable times at all.
     *
     * Three very different situations collapse into the same empty list, and
     * the caller needs to tell them apart to say anything useful: a date in the
     * past calls for a different date, a closing day calls for a different day,
     * and a service that is simply over calls for tomorrow.
     */
    private function reasonForNoSlots(string $date): string
    {
        // Both sides are ISO dates, so comparing them as strings is ordering
        // by calendar date.
        if ($date < $this->clock->localDate($this->clock->now())) {
            return "{$date} has already passed.";
        }

        $startOfDay = $this->clock->parseLocal($date, self::LOCAL_DAY_START);

        if ($this->openingHours->rangesFor($startOfDay) === []) {
            return 'The restaurant is closed on '.$this->clock->toLocal($startOfDay)->format('l').'s.';
        }

        return "The last booking time on {$date} has already passed.";
    }

    /**
     * Every future slot the restaurant is open for on a local date.
     *
     * @return list<CarbonImmutable> UTC.
     */
    private function bookableSlotsOn(string $date): array
    {
        $slotMinutes = (int) config('restaurant.slot_minutes');
        $now = $this->clock->now();

        $cursor = $this->clock->parseLocal($date, self::LOCAL_DAY_START);
        $endOfDay = $cursor->addDay();

        $slots = [];

        while ($cursor->lessThan($endOfDay)) {
            if ($cursor->greaterThan($now) && $this->openingHours->isBookable($cursor)) {
                $slots[] = $cursor;
            }

            $cursor = $cursor->addMinutes($slotMinutes);
        }

        return $slots;
    }
}
