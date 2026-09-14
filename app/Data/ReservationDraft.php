<?php

namespace App\Data;

use App\Enums\BookingChannel;
use Carbon\CarbonImmutable;

/**
 * A validated booking, not yet committed.
 *
 * Named a draft rather than a request on purpose: it travels alongside
 * Laravel\Mcp\Request, which is an actual transport request, and calling both
 * the same thing made the tool harder to read than it needed to be. The pairing
 * with ReservationResult also says which way the data flows — a draft goes in,
 * an outcome comes back.
 *
 * Immutable by design: a readonly object cannot pick up state between requests,
 * which is what keeps it safe to pass around under Octane.
 */
final readonly class ReservationDraft
{
    /**
     * @param  CarbonImmutable  $requestedFor  UTC. Already converted from the
     *                                         customer's local wall-clock time.
     */
    public function __construct(
        public string $customerName,
        public string $customerEmail,
        public ?string $customerPhone,
        public int $partySize,
        public CarbonImmutable $requestedFor,
        public ?string $notes = null,
        public BookingChannel $channel = BookingChannel::Mcp,
    ) {}

    /**
     * The slots this booking would occupy.
     */
    public function dwellWindow(): DwellWindow
    {
        return DwellWindow::forParty($this->requestedFor, $this->partySize);
    }
}
