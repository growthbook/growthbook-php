<?php

namespace Growthbook;

/**
 * Treats strings as UTF-16 code units, the way JavaScript does, so hashing and
 * string ordering match the JS SDK. Decodes UTF-8 by hand, so neither mbstring
 * nor iconv is needed.
 *
 * @internal
 */
class Utf16
{
    /**
     * The UTF-16 code units of a UTF-8 string. An invalid byte becomes U+FFFD.
     *
     * @param string $value
     * @return int[]
     */
    public static function codeUnits(string $value): array
    {
        $units = [];
        $length = strlen($value);
        $i = 0;
        while ($i < $length) {
            $byte = ord($value[$i]);
            if ($byte < 0x80) {
                $units[] = $byte;
                $i++;
                continue;
            }

            if ($byte >= 0xC2 && $byte <= 0xDF) {
                $count = 1;
                $codePoint = $byte & 0x1F;
                $min = 0x80;
            } elseif ($byte >= 0xE0 && $byte <= 0xEF) {
                $count = 2;
                $codePoint = $byte & 0x0F;
                $min = 0x800;
            } elseif ($byte >= 0xF0 && $byte <= 0xF4) {
                $count = 3;
                $codePoint = $byte & 0x07;
                $min = 0x10000;
            } else {
                $units[] = 0xFFFD;
                $i++;
                continue;
            }

            $valid = true;
            for ($j = 1; $j <= $count; $j++) {
                if ($i + $j >= $length) {
                    $valid = false;
                    break;
                }
                $next = ord($value[$i + $j]);
                if (($next & 0xC0) !== 0x80) {
                    $valid = false;
                    break;
                }
                $codePoint = ($codePoint << 6) | ($next & 0x3F);
            }
            // Overlong forms, surrogates and values past U+10FFFF aren't valid UTF-8
            if (!$valid || $codePoint < $min || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
                $units[] = 0xFFFD;
                $i++;
                continue;
            }

            if ($codePoint >= 0x10000) {
                $codePoint -= 0x10000;
                $units[] = 0xD800 | ($codePoint >> 10);
                $units[] = 0xDC00 | ($codePoint & 0x3FF);
            } else {
                $units[] = $codePoint;
            }
            $i += $count + 1;
        }
        return $units;
    }

    /**
     * FNV-1a 32-bit hash over UTF-16 code units, like the JS SDK's hashFnv32a
     *
     * @param string $value
     * @return int
     */
    public static function fnv1a32(string $value): int
    {
        // ASCII bytes are their own code units
        if (!preg_match('/[\x80-\xFF]/', $value)) {
            return (int) hexdec(hash('fnv1a32', $value));
        }

        $hash = 0x811C9DC5;
        foreach (static::codeUnits($value) as $unit) {
            $hash ^= $unit;
            $hash = ($hash * 0x01000193) & 0xFFFFFFFF;
        }
        return $hash;
    }

    /**
     * Compares two strings by UTF-16 code units, like JS's < and >
     *
     * @param string $a
     * @param string $b
     * @return int -1, 0 or 1
     */
    public static function compare(string $a, string $b): int
    {
        // Byte order is code unit order for ASCII
        if (!preg_match('/[\x80-\xFF]/', $a . $b)) {
            return strcmp($a, $b) <=> 0;
        }
        // PHP compares arrays by length first, so compare unit by unit
        $unitsA = static::codeUnits($a);
        $unitsB = static::codeUnits($b);
        $length = min(count($unitsA), count($unitsB));
        for ($i = 0; $i < $length; $i++) {
            if ($unitsA[$i] !== $unitsB[$i]) {
                return $unitsA[$i] <=> $unitsB[$i];
            }
        }
        return count($unitsA) <=> count($unitsB);
    }
}
