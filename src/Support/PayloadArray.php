<?php

namespace JoanFo\DiscordWebhooks\Support;

/**
 * Framework-free helpers for tidying nested payload arrays.
 */
final class PayloadArray
{
    /**
     * Recursively drop blank entries, including arrays that end up empty. Lists are
     * re-indexed so they still encode as JSON arrays, associative keys are preserved.
     *
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    public static function withoutBlank(array $values): array
    {
        $isList = array_is_list($values);
        $result = [];

        foreach ($values as $key => $value) {
            if (is_array($value)) {
                $value = self::withoutBlank($value);
            }

            if ($value === null || $value === [] || (is_string($value) && trim($value) === '')) {
                continue;
            }

            $result[$key] = $value;
        }

        return $isList ? array_values($result) : $result;
    }
}
