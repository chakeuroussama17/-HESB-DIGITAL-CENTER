<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Decimal-string money helpers. Values are strings with 2 dp ("1234.50") and all
 * arithmetic goes through bcmath, never floats.
 */
final class Money
{
    public const SCALE = 2;

    /**
     * Parse user / spreadsheet input into a normalised decimal string.
     * Accepts "1,234.5", "(1,234.50)", "-12", "RM 1 000", ints and floats. Blank → null.
     */
    public static function parse(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_int($value)) {
            return self::normalise((string) $value);
        }
        if (is_float($value)) {
            // Spreadsheet cells arrive as floats; snap to cents through string formatting.
            return self::normalise(number_format($value, 4, '.', ''));
        }

        $s = trim((string) $value);
        if ($s === '' || $s === '-') {
            return null;
        }

        $negative = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) {
            $negative = true;
            $s = trim($m[1]);
        }
        $s = preg_replace('/^RM/i', '', $s);
        $s = str_replace([',', ' ', "\u{00A0}"], '', $s);
        if (str_starts_with($s, '-')) {
            $negative = ! $negative;
            $s = substr($s, 1);
        }

        if (! preg_match('/^(\d+(\.\d+)?|\.\d+)$/', $s)) {
            throw new InvalidArgumentException("Not a valid amount: {$value}");
        }

        $result = self::normalise(($negative ? '-' : '').$s);
        if (bccomp(ltrim($result, '-'), '9999999999999.99', 2) > 0) {
            throw new InvalidArgumentException("Amount too large: {$value}");
        }

        return $result;
    }

    public static function isValid(mixed $value): bool
    {
        try {
            self::parse($value);

            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    /** Round half away from zero to 2 dp (bcmath truncates, so shift by half a cent first). */
    public static function normalise(string $n): string
    {
        $half = str_starts_with($n, '-') ? '-0.005' : '0.005';

        return self::clean(bcadd($n, $half, self::SCALE));
    }

    public static function add(?string ...$values): string
    {
        $sum = '0';
        foreach ($values as $v) {
            $sum = bcadd($sum, $v ?? '0', self::SCALE);
        }

        return self::clean($sum);
    }

    public static function sub(?string $a, ?string $b): string
    {
        return self::clean(bcsub($a ?? '0', $b ?? '0', self::SCALE));
    }

    /** (a / b) × 100 to 2 dp, or null when b is zero/blank. Uses |b| so the sign follows a. */
    public static function percent(?string $a, ?string $b): ?string
    {
        if ($b === null || bccomp($b, '0', self::SCALE) === 0) {
            return null;
        }
        $b = ltrim($b, '-');

        return self::normalise(bcdiv(bcmul($a ?? '0', '100', 6), $b, 6));
    }

    public static function cmp(?string $a, ?string $b): int
    {
        return bccomp($a ?? '0', $b ?? '0', self::SCALE);
    }

    public static function isZero(?string $a): bool
    {
        return self::cmp($a, '0') === 0;
    }

    /**
     * Accounting display: 1,234.56 or (1,234.56).
     * With $asCost a positive value is a deduction and is bracketed; a negative cost
     * (a credit) is shown plain.
     */
    public static function format(?string $value, bool $asCost = false, string $blank = '–'): string
    {
        if ($value === null) {
            return $blank;
        }
        $negative = bccomp($value, '0', self::SCALE) < 0;
        $abs = $negative ? substr($value, 1) : $value;
        [$int, $dec] = array_pad(explode('.', $abs), 2, '00');
        $formatted = number_format((int) $int).'.'.str_pad(substr($dec, 0, 2), 2, '0');

        $bracket = $asCost ? (! $negative && ! self::isZero($value)) : $negative;

        return $bracket ? "({$formatted})" : $formatted;
    }

    private static function clean(string $n): string
    {
        return $n === '-0.00' ? '0.00' : $n;
    }
}
