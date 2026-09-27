<?php

declare(strict_types=1);

namespace Plugins\Payment\Domain\Rules;

/**
 * Does the name a telco has on record for a number plausibly belong to the
 * person claiming it?
 *
 * Registered names come back upper-case, in any order, sometimes with a middle
 * name the person never types ("NAKAMYA MARY JANE" for "Mary Nakamya"). So the
 * comparison is on name PARTS, ignoring case, accents, order and punctuation:
 * every part of the shorter name must appear in the longer one, and at least
 * two parts must match — unless both names are a single word.
 *
 * This is a screening aid, not identity proof. A "no" means a person should
 * look; a "yes" means the names are consistent.
 */
final class NameMatch
{
    public static function check(string $registered, string $claimed): bool
    {
        $a = self::parts($registered);
        $b = self::parts($claimed);
        if ($a === [] || $b === []) {
            return false;
        }

        [$shorter, $longer] = \count($a) <= \count($b) ? [$a, $b] : [$b, $a];
        if (array_diff($shorter, $longer) !== []) {
            return false;
        }

        return \count($shorter) >= 2 || (\count($a) === 1 && \count($b) === 1);
    }

    /** @return list<string> */
    private static function parts(string $name): array
    {
        $name = mb_strtoupper(trim($name));
        if (class_exists(\Normalizer::class)) {
            // "É" → "E" + combining accent, then drop the accent.
            $name = preg_replace('/\p{Mn}+/u', '', (string) \Normalizer::normalize($name, \Normalizer::FORM_D)) ?? $name;
        }
        $name = preg_replace('/[^\p{L}\s]+/u', ' ', $name) ?? '';

        return array_values(array_unique(array_filter(
            preg_split('/\s+/', $name) ?: [],
            static fn(string $part): bool => mb_strlen($part) >= 2,
        )));
    }
}
