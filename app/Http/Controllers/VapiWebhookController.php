<?php

namespace App\Http\Controllers;

use App\Data\AlternativeSlot;
use App\Data\ConfirmedReservation;
use App\Data\ReservationDraft;
use App\Data\ReservationResult;
use App\Enums\BookingChannel;
use App\Services\ReservationService;
use App\Services\TimeResolver;
use App\Support\FailureReference;
use App\Support\FreeText;
use App\Support\RestaurantClock;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * The voice transport: the same reservation logic, reached by telephone.
 *
 * Vapi runs the call and the speech. When its agent decides to book, it posts
 * the tool call here, and from the mapping down this is the code the MCP tool
 * already uses — the same ReservationDraft, the same ReservationService, the
 * same atomic Lua script. That reuse is the whole demonstration: the rules about
 * when a table may be sold live in one place, and two callers that share nothing
 * else share them exactly.
 *
 * What differs is presentation, and only presentation:
 *
 *   - the answer is a sentence, because it is about to be spoken, where MCP gets
 *     a structured object for a model to reason over;
 *   - times are said the way a person says them — "tomorrow at 7:30 PM", not
 *     "2026-09-15T19:30" — since "nineteen thirty" is not an answer anyone wants
 *     read back to them on the phone.
 *
 * Availability is deliberately not exposed here. It does not need to be: a full
 * slot already comes back from the service with the nearest times that can seat
 * the party, which on a phone call is the same conversation a person would have
 * — "seven is gone, I can do seven thirty".
 *
 * @see https://docs.vapi.ai/tools/custom-tools
 */
class VapiWebhookController extends Controller
{
    public function __construct(
        private readonly ReservationService $reservations,
        private readonly TimeResolver $time,
        private readonly RestaurantClock $clock,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $this->rememberCall($request);

        $results = [];

        foreach ($this->toolCalls($request) as $call) {
            $results[] = $this->answer($call);
        }

        return response()->json(['results' => $results]);
    }

    /**
     * Tie every log line of this request to the phone call it belongs to.
     *
     * The equivalent of mcp_session on the other transport, and the reason the
     * spoken failure below never recites a reference: the platform already holds
     * the call, with the number that made it and a recording, so "which call was
     * this?" is answerable without asking a customer to read out a ULID.
     */
    private function rememberCall(Request $request): void
    {
        /*
         * Cleared before being conditionally set, for the reason spelled out in
         * AssignCorrelationId: a worker outlives the request, so each one states
         * its own values rather than trusting the last to have tidied up.
         */
        Context::forget('vapi_call');

        if (($callId = $request->input('message.call.id')) !== null) {
            Context::add('vapi_call', (string) $callId);
        }
    }

    /**
     * The tool calls in this payload, in whichever shape they arrived.
     *
     * @return list<array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function toolCalls(Request $request): array
    {
        /** @var array<string, mixed> $message */
        $message = (array) $request->input('message', []);

        /*
         * Vapi posts every event of a call to the same URL: status updates,
         * transcripts, the end-of-call report. Only tool-calls asks us for
         * anything, and everything else has to come back 200 having done
         * nothing, or the platform retries a message we were never meant to act
         * on in the first place.
         *
         * toolCallList is the flat form; toolCalls is the OpenAI-shaped one,
         * which nests the name under "function" and may send the arguments as a
         * JSON string. Reading both means a change of shape on their side is
         * not an outage on ours.
         */
        $calls = $message['toolCallList'] ?? $message['toolCalls'] ?? [];

        $normalised = [];

        foreach ((array) $calls as $call) {
            $call = (array) $call;
            $function = (array) ($call['function'] ?? []);

            $arguments = $call['arguments'] ?? $function['arguments'] ?? [];

            if (is_string($arguments)) {
                $arguments = json_decode($arguments, true) ?? [];
            }

            $normalised[] = [
                'id' => (string) ($call['id'] ?? ''),
                'name' => (string) ($call['name'] ?? $function['name'] ?? ''),
                'arguments' => (array) $arguments,
            ];
        }

        return $normalised;
    }

    /**
     * One call in, one result out, carrying the id Vapi matches it back on.
     *
     * @param  array{id: string, name: string, arguments: array<string, mixed>}  $call
     * @return array<string, string>
     */
    private function answer(array $call): array
    {
        try {
            return [
                'toolCallId' => $call['id'],
                'result' => match ($call['name']) {
                    'make_reservation' => $this->book($call['arguments']),
                    default => sprintf(
                        'This line only takes bookings, and "%s" is not one. Tell the customer to ring the restaurant directly for anything else.',
                        $call['name'],
                    ),
                },
            ];
        } catch (Throwable $e) {
            /*
             * Caught per call, not per request. A failure that escaped here
             * would answer the whole batch with a 500, which on a live phone
             * call leaves the agent with nothing at all to say — including for
             * the calls that worked.
             */
            FailureReference::for($e, 'webhook.failed', ['tool' => $call['name']]);

            return [
                'toolCallId' => $call['id'],
                'error' => 'Something went wrong and no reservation was made. Apologise, and ask the customer to try again in a moment.',
            ];
        }
    }

    /**
     * Map the agent's arguments onto the very draft the MCP tool builds, then
     * say the outcome out loud.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function book(array $arguments): string
    {
        $validator = Validator::make($arguments, [
            'customer_name' => ['required', 'string', 'min:2', 'max:120'],
            'customer_email' => ['required', 'email:rfc', 'max:255'],
            'customer_phone' => ['nullable', 'string', 'max:32'],
            'party_size' => ['required', 'integer', 'between:1,20'],
            'date' => ['required_without:natural_time', 'nullable', 'date_format:Y-m-d'],
            'time' => ['required_with:date', 'nullable', 'date_format:H:i'],
            'natural_time' => ['required_without:date', 'nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        /*
         * A missing field is not a failure, it is the next question to ask. The
         * agent is in the middle of a conversation and can simply ask for it, so
         * this comes back as a result rather than an error — the same reasoning
         * that makes "unavailable" a successful MCP call.
         */
        if ($validator->fails()) {
            return 'That booking is not complete yet: '.$validator->errors()->first()
                .' Ask the customer for it, then try again.';
        }

        /** @var array<string, mixed> $input */
        $input = $validator->validated();

        $when = $this->time->resolve(
            date: $input['date'] ?? null,
            time: $input['time'] ?? null,
            phrase: $input['natural_time'] ?? null,
        );

        if ($when === null) {
            return 'I could not work out which day and time that was. Ask the customer to say the date and the time plainly, for example "Friday at eight in the evening".';
        }

        $result = $this->reservations->reserve(new ReservationDraft(
            customerName: $input['customer_name'],
            customerEmail: $input['customer_email'],
            customerPhone: $input['customer_phone'] ?? null,
            partySize: $input['party_size'],
            requestedFor: $when->at,
            notes: FreeText::clean($input['notes'] ?? null),
            // The single line that differs from the MCP tool, and it exists so
            // the reservations table records which transport sold each table.
            channel: BookingChannel::Vapi,
        ));

        return $result->isConfirmed()
            ? $this->confirm($result->reservation)
            : $this->offer($result);
    }

    /**
     * A confirmation, phrased as the restaurant would read it back.
     *
     * The customer's own notes are not repeated here. Echoing free text straight
     * back into what a language model is about to speak buys nothing — the agent
     * sent it in the first place — and it is the one field on this call an
     * outsider writes, so it stays data all the way to the staff who read it.
     */
    private function confirm(ConfirmedReservation $booking): string
    {
        return sprintf(
            'Booked: %s, %s, %s at %s. The table is held until %s. Reference %s — for the record; no need to read it out unless the customer asks.',
            $booking->customerName,
            $this->guests($booking->partySize),
            $this->clock->speakDate($booking->reservedFor),
            $this->spokenTime($booking->reservedFor),
            $this->spokenTime($booking->window->endsAt),
            $booking->reference,
        );
    }

    /**
     * A refusal, phrased as an offer.
     *
     * The service has already worked out which nearby times can seat the party
     * for their whole stay, so a full slot is never a dead end on the phone: it
     * is the moment to suggest something else, which is what the person on the
     * other end of the line would do.
     */
    private function offer(ReservationResult $result): string
    {
        if ($result->alternatives === []) {
            return $result->reason.' There is nothing close enough to offer, so ask the customer about another day.';
        }

        return sprintf(
            '%s Offer one of these instead: %s.',
            $result->reason,
            $this->spokenAlternatives($result->alternatives),
        );
    }

    /**
     * @param  list<AlternativeSlot>  $alternatives
     */
    private function spokenAlternatives(array $alternatives): string
    {
        $days = array_unique(array_map(
            fn (AlternativeSlot $slot): string => $this->clock->localDate($slot->startsAt),
            $alternatives,
        ));

        /*
         * All on one day: name the day once and then list the times, because
         * "tomorrow at 7:30, tomorrow at 8:00, tomorrow at 8:30" is not how
         * anybody speaks. The alternative search steps over closed hours to
         * reach the next service, so options really can land on two days, and
         * then each one has to carry its own.
         */
        if (count($days) === 1) {
            return sprintf(
                '%s at %s',
                $this->clock->speakDate($alternatives[0]->startsAt),
                $this->inWords(array_map(
                    fn (AlternativeSlot $slot): string => $this->spokenTime($slot->startsAt),
                    $alternatives,
                )),
            );
        }

        return $this->inWords(array_map(
            fn (AlternativeSlot $slot): string => sprintf(
                '%s at %s',
                $this->clock->speakDate($slot->startsAt),
                $this->spokenTime($slot->startsAt),
            ),
            $alternatives,
        ));
    }

    /**
     * "7:30 PM, 8:00 PM or 8:30 PM" — joined with "or", since these are a choice
     * between times and not a list of them.
     *
     * @param  list<string>  $items
     */
    private function inWords(array $items): string
    {
        if (count($items) < 2) {
            return (string) ($items[0] ?? '');
        }

        $last = array_pop($items);

        return implode(', ', $items).' or '.$last;
    }

    /**
     * @param  CarbonImmutable  $utc  UTC.
     */
    private function spokenTime(CarbonImmutable $utc): string
    {
        return $this->clock->toLocal($utc)->format('g:i A');
    }

    private function guests(int $partySize): string
    {
        return $partySize === 1 ? '1 person' : $partySize.' people';
    }
}
