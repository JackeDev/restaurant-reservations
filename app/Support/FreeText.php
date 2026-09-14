<?php

namespace App\Support;

/**
 * Normalisation for text a customer wrote, shared by every entry point.
 *
 * This is data hygiene, not a security filter, and the distinction is the point:
 * nothing is rejected for its content. A note is stored exactly as written and
 * escaped only on output, because a blocklist aimed at quotes and angle brackets
 * mangles "Allergy: sulfites <10ppm" and "the O'Brien family" while stopping
 * nothing that matters. What goes is only what would misrepresent how the note
 * reads: control and formatting characters, zero-width spaces and the
 * right-to-left override among them.
 *
 * It lives here rather than on either adapter so that the MCP tool and the voice
 * webhook cannot drift into treating the same sentence differently.
 */
final class FreeText
{
    public static function clean(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $clean = trim((string) preg_replace('/[\p{Cc}\p{Cf}]/u', '', $text));

        return $clean === '' ? null : $clean;
    }
}
