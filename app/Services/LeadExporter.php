<?php

namespace App\Services;

use App\Models\Lead;
use App\Support\Spreadsheet;
use Illuminate\Database\Eloquent\Builder;
use OpenSpout\Common\Entity\Cell\StringCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams leads to CSV or XLSX row by row (low memory, cPanel friendly).
 * Every cell is written as text and neutralised against formula injection.
 */
class LeadExporter
{
    public const HEADERS = [
        'Lead ID', 'Customer Name', 'Email Address', 'Phone Number', 'Source Website', 'Customer Website',
        'Interested Service', 'Inquiry Summary', 'Lead Status', 'Assigned Staff', 'Conversations',
        'Created (IST)', 'Last Interaction (IST)',
    ];

    public static function download(Builder $query, string $format, string $tz): StreamedResponse
    {
        $stamp = now($tz)->format('Y-m-d_His');
        $filename = "leads_{$stamp}.{$format}";

        $headers = [
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($query, $tz) {
                $writer = new XlsxWriter();
                $writer->openToFile('php://output');
                $writer->addRow(self::textRow(self::HEADERS, (new Style())->setFontBold()));
                self::each($query, function (Lead $lead) use ($writer, $tz) {
                    $writer->addRow(self::textRow(self::values($lead, $tz)));
                });
                $writer->close();
            }, $filename, $headers + ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($query, $tz) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows Hindi/Unicode correctly
            fputcsv($out, self::HEADERS);
            self::each($query, function (Lead $lead) use ($out, $tz) {
                fputcsv($out, Spreadsheet::row(self::values($lead, $tz)));
            });
            fclose($out);
        }, $filename, $headers + ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public static function values(Lead $lead, string $tz): array
    {
        return [
            $lead->reference,
            $lead->name,
            $lead->email,
            $lead->phone,
            $lead->property?->site_name,
            $lead->website,
            $lead->interested_service ? (Lead::SERVICES[$lead->interested_service] ?? $lead->interested_service) : '',
            $lead->inquiry_summary,
            Lead::STATUSES[$lead->status] ?? $lead->status,
            $lead->assignee?->name,
            (string) ($lead->conversations_count ?? 0),
            $lead->created_at?->setTimezone($tz)->format('Y-m-d H:i'),
            $lead->last_interaction_at?->setTimezone($tz)->format('Y-m-d H:i'),
        ];
    }

    private static function each(Builder $query, callable $fn): void
    {
        $page = 1;
        do {
            $batch = (clone $query)->forPage($page++, 500)->get();
            foreach ($batch as $lead) {
                $fn($lead);
            }
        } while ($batch->count() === 500);
    }

    /** Explicit string cells: OpenSpout would otherwise turn "=..." into a live formula. */
    private static function textRow(array $values, ?Style $style = null): Row
    {
        $cells = array_map(fn ($v) => new StringCell(Spreadsheet::cell($v), null), $values);

        return new Row($cells, $style);
    }
}
