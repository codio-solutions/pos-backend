<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Money
{
    public static function cents(string|int|float $value): int
    {
        $text = (string) $value;
        if (! preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?$/', $text, $parts)) {
            throw ValidationException::withMessages(['value' => 'Use a decimal monetary amount.']);
        }
        $fraction = str_pad($parts[3] ?? '', 3, '0');
        $cents = (int) $parts[2] * 100 + (int) substr($fraction, 0, 2) + ((int) $fraction[2] >= 5 ? 1 : 0);

        return $parts[1] === '-' ? -$cents : $cents;
    }

    public static function multiply(string|int|float $value, int $qty): string
    {
        $total = self::cents($value) * $qty;
        if ($total < 0 || $total > 99999999999999) {
            throw ValidationException::withMessages(['items' => 'Line total exceeds the supported amount.']);
        }

        return intdiv($total, 100).'.'.str_pad((string) ($total % 100), 2, '0', STR_PAD_LEFT);
    }
}
