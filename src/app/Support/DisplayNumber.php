<?php

namespace App\Support;

class DisplayNumber
{
    public static function decimal(int|float|string|null $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 2, ',', '.');
    }

    public static function tuition(int|float|string|null $value): string
    {
        return $value === null ? '—' : number_format((float) $value, 0, ',', '.');
    }
}
