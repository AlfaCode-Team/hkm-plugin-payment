<?php

declare(strict_types=1);

namespace Plugins\Payment\Support;

/**
 * User-facing copy, read from resources/lang/{locale}/messages.php under the
 * `payment` namespace.
 *
 * The i18n plugin is OPTIONAL: when it is installed its trans_or() resolves the
 * key in the active locale; when it is not, the English default passed here is
 * used. Either way the placeholders (":name") are substituted the same way.
 */
final class Messages
{
    /** @param array<string, string|int|float> $replace */
    public static function get(string $key, string $default, array $replace = []): string
    {
        if (\function_exists('trans_or')) {
            return trans_or('payment::messages.' . $key, $default, $replace);
        }

        if ($replace === []) {
            return $default;
        }

        uksort($replace, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $pairs = [];
        foreach ($replace as $name => $value) {
            $pairs[':' . $name] = (string) $value;
        }

        return strtr($default, $pairs);
    }
}
