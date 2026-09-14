<?php

namespace App\Console\Commands;

use RuntimeException;

/**
 * One diagnostic check did not pass.
 *
 * Its message is not an error report, it is the line the reviewer reads, so it
 * says what is wrong and what that costs: "no ANTHROPIC_API_KEY — natural
 * language falls back to Carbon only" rather than "configuration error".
 */
final class CheckFailed extends RuntimeException {}
