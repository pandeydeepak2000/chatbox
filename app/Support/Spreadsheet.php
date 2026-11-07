<?php

namespace App\Support;

class Spreadsheet
{
    /**
     * Neutralise spreadsheet formula injection (CSV / XLSX).
     * Cells starting with = + - @ TAB or CR are prefixed with an apostrophe,
     * which Excel, LibreOffice and Google Sheets all treat as "text".
     */
    public static function cell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'" . $value;
        }

        return $value;
    }

    public static function row(array $values): array
    {
        return array_map([self::class, 'cell'], $values);
    }
}
