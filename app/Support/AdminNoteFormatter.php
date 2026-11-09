<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Auth;

class AdminNoteFormatter
{
    public static function authorName(?object $user = null): string
    {
        $user ??= Auth::user();

        foreach ([
            $user?->pharmacist_display_name,
            $user?->name,
            $user?->email,
        ] as $candidate) {
            $candidate = trim((string) ($candidate ?? ''));

            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'Unknown user';
    }

    public static function format(
        string $note,
        ?object $user = null,
        ?CarbonInterface $createdAt = null,
    ): string {
        $createdAt ??= now();

        return sprintf(
            '%s — %s: %s',
            $createdAt->format('d-m-Y H:i'),
            self::authorName($user),
            trim($note),
        );
    }

    public static function append(mixed $existing, string $formattedNote): array|string
    {
        if (is_array($existing)) {
            $existing[] = $formattedNote;

            return $existing;
        }

        $existing = rtrim((string) ($existing ?? ''));

        return $existing === ''
            ? $formattedNote
            : $existing."\n".$formattedNote;
    }
}
