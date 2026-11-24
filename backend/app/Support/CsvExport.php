<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/*
| Streams a CSV file to the browser row by row.
|
| Two things to know about CSV exports:
|
| 1. Streaming: rows are written as they are read, so exporting 100 000
|    bookings does not need 100 000 rows in memory at once.
|
| 2. "CSV injection" (also called formula injection): spreadsheet programs
|    treat a cell starting with = + - @ as a formula. A traveler named
|    `=HYPERLINK("http://evil.example", "Click")` would become a live link
|    in the admin's Excel. Prefixing such cells with a single quote makes
|    Excel display them as plain text.
*/
final class CsvExport
{
    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function download(string $filename, array $headings, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');
            // A UTF-8 "byte order mark" so Excel shows accented names correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings, escape: '');

            foreach ($rows as $row) {
                fputcsv($out, array_map(self::sanitize(...), $row), escape: '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function sanitize(string|int|float|bool|null $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
