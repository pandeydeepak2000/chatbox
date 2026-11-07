<?php

namespace App\Services\Knowledge;

use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Pure-PHP text extraction (cPanel compatible: no pdftotext, LibreOffice or Python).
 * Hard limits protect shared-hosting memory/CPU and defend against zip bombs.
 */
class DocumentExtractor
{
    public const ALLOWED = [
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'application/csv' => 'csv',
        'application/pdf' => 'pdf',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    ];
    public const MAX_BYTES = 10 * 1024 * 1024;
    public const MAX_CHARS = 1_500_000;
    public const MAX_ROWS = 20_000;
    private const MAX_UNCOMPRESSED = 60 * 1024 * 1024;

    /** Decide the real type from content + extension; returns the internal kind or null. */
    public static function detectKind(string $path, string $originalName): ?string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (in_array($mime, ['application/zip', 'application/octet-stream', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)) {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true) {
                return null;
            }
            $kind = $zip->locateName('word/document.xml') !== false ? 'docx' : ($zip->locateName('xl/workbook.xml') !== false ? 'xlsx' : null);
            $zip->close();

            return $kind;
        }
        if (str_starts_with($mime, 'text/') || $mime === 'application/csv') {
            if (in_array($mime, ['text/html', 'text/xml'], true) && ! in_array($ext, ['txt', 'csv'], true)) {
                return null;
            }

            return $ext === 'csv' || $mime === 'text/csv' || $mime === 'application/csv' ? 'csv' : 'txt';
        }

        return null;
    }

    public static function extract(string $path, string $kind): string
    {
        $text = match ($kind) {
            'txt' => self::readText($path),
            'csv' => self::csv($path),
            'pdf' => self::pdf($path),
            'docx' => self::docx($path),
            'xlsx' => self::xlsx($path),
            default => throw new \RuntimeException('Unsupported file type.'),
        };
        $text = preg_replace("/[ \t]+/u", ' ', $text) ?? '';
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? '';
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('No readable text was found in this file (scanned PDFs/images are not supported).');
        }

        return mb_substr($text, 0, self::MAX_CHARS);
    }

    private static function readText(string $path): string
    {
        $raw = (string) file_get_contents($path, false, null, 0, self::MAX_BYTES);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return str_replace("\r\n", "\n", preg_replace('/^\xEF\xBB\xBF/', '', $raw));
    }

    private static function csv(string $path): string
    {
        $fh = fopen($path, 'r');
        $lines = [];
        $header = null;
        $rows = 0;
        while (($row = fgetcsv($fh, 0, ',', '"', '\\')) !== false && $rows++ < self::MAX_ROWS) {
            $row = array_map(fn ($c) => trim((string) $c), $row);
            if ($header === null) {
                $header = $row;
                continue;
            }
            $pairs = [];
            foreach ($row as $i => $cell) {
                if ($cell !== '') {
                    $pairs[] = (($header[$i] ?? '') !== '' ? $header[$i] . ': ' : '') . $cell;
                }
            }
            if ($pairs) {
                $lines[] = implode(' | ', $pairs);
            }
        }
        fclose($fh);

        return self::readTextEncoding(implode("\n", $lines));
    }

    private static function readTextEncoding(string $s): string
    {
        return mb_check_encoding($s, 'UTF-8') ? $s : mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    private static function pdf(string $path): string
    {
        $config = new \Smalot\PdfParser\Config();
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(64 * 1024 * 1024);
        $pdf = (new PdfParser([], $config))->parseFile($path);
        $out = [];
        foreach (array_slice($pdf->getPages(), 0, 500) as $page) {
            $out[] = $page->getText();
        }

        return implode("\n\n", $out);
    }

    private static function guardZip(\ZipArchive $zip): void
    {
        $total = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $total += (int) ($stat['size'] ?? 0);
            if ($total > self::MAX_UNCOMPRESSED || ($stat['comp_size'] ?? 1) > 0 && ($stat['size'] ?? 0) / max(1, $stat['comp_size']) > 200) {
                throw new \RuntimeException('This file expands to an unsafe size and was rejected.');
            }
        }
    }

    private static function docx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('Could not open the DOCX file.');
        }
        self::guardZip($zip);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        $xml = preg_replace(['#</w:p>#', '#<w:tab/>#', '#<w:br\s*/>#'], ["\n", "\t", "\n"], $xml) ?? '';
        $text = strip_tags($xml);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function xlsx(string $path): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) === true) {
            self::guardZip($zip);
            $zip->close();
        }
        $reader = new XlsxReader();
        $reader->open($path);
        $lines = [];
        $rows = 0;
        foreach ($reader->getSheetIterator() as $sheet) {
            $header = null;
            $lines[] = '# ' . $sheet->getName();
            foreach ($sheet->getRowIterator() as $row) {
                if (++$rows > self::MAX_ROWS) {
                    break 2;
                }
                $cells = array_map(fn ($v) => trim(is_scalar($v) ? (string) $v : ($v instanceof \DateTimeInterface ? $v->format('Y-m-d') : '')), $row->toArray());
                if ($header === null) {
                    $header = $cells;
                    continue;
                }
                $pairs = [];
                foreach ($cells as $i => $cell) {
                    if ($cell !== '') {
                        $pairs[] = (($header[$i] ?? '') !== '' ? $header[$i] . ': ' : '') . $cell;
                    }
                }
                if ($pairs) {
                    $lines[] = implode(' | ', $pairs);
                }
            }
        }
        $reader->close();

        return implode("\n", $lines);
    }
}
