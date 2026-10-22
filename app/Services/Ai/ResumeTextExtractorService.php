<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\CandidateDocument;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

class ResumeTextExtractorService
{
    /**
     * Extract text from a stored private CandidateDocument with format validation and scanned-document / OCR detection.
     *
     * @return array{
     *   text: string,
     *   is_scanned_document: bool,
     *   ocr_used: bool,
     *   requires_manual_review: bool,
     *   warning_code: ?string,
     *   warning_message: ?string
     * }
     */
    public function extractFromDocument(CandidateDocument $document): array
    {
        if (! Storage::disk($document->storage_disk)->exists($document->storage_path)) {
            throw new RuntimeException('DOCUMENT_MISSING: Stored resume file could not be located in private storage.', 404);
        }

        $rawBytes = (string) Storage::disk($document->storage_disk)->get($document->storage_path);
        if (trim($rawBytes) === '' || strlen($rawBytes) === 0) {
            throw new RuntimeException('EMPTY_DOCUMENT: Uploaded resume document is empty (0 bytes).', 422);
        }

        $ext = strtolower(pathinfo($document->original_filename, PATHINFO_EXTENSION));
        $mime = strtolower((string) $document->mime_type);

        if ($ext === 'pdf' || str_contains($mime, 'pdf')) {
            return $this->extractFromPdfBytes($rawBytes);
        }

        if ($ext === 'docx' || str_contains($mime, 'officedocument.wordprocessingml')) {
            return $this->extractFromDocxBytes($rawBytes);
        }

        if ($ext === 'doc' || str_contains($mime, 'msword')) {
            return $this->extractFromLegacyDocBytes($rawBytes);
        }

        if ($ext === 'txt' || str_contains($mime, 'text/plain')) {
            return $this->extractFromPlainTextBytes($rawBytes);
        }

        throw new RuntimeException("UNSUPPORTED_DOCUMENT_FORMAT: Format '{$ext}' ({$mime}) is not supported for automated resume parsing.", 422);
    }

    /**
     * @return array{text: string, is_scanned_document: bool, ocr_used: bool, requires_manual_review: bool, warning_code: ?string, warning_message: ?string}
     */
    public function extractFromPdfBytes(string $rawBytes): array
    {
        if (! str_starts_with(ltrim($rawBytes), '%PDF-')) {
            throw new RuntimeException('MALFORMED_DOCUMENT: File does not contain a valid %PDF header.', 422);
        }

        $extractedText = $this->extractPdfStreamsNative($rawBytes);

        if (strlen(trim($extractedText)) < 15) {
            $cliText = $this->extractPdfViaPopplerCli($rawBytes);
            if (strlen(trim($cliText)) > strlen(trim($extractedText))) {
                $extractedText = $cliText;
            }
        }

        $cleaned = $this->normalizeExtractedWhitespace($extractedText);
        if (strlen($cleaned) >= 10) {
            return [
                'text' => $cleaned,
                'is_scanned_document' => false,
                'ocr_used' => false,
                'requires_manual_review' => false,
                'warning_code' => null,
                'warning_message' => null,
            ];
        }

        // Check if this is a scanned / image-only PDF
        $hasImageObjects = (bool) preg_match('/\/Subtype\s*\/Image|\/XObject|\/Page\b/i', $rawBytes);
        if ($hasImageObjects) {
            return [
                'text' => '',
                'is_scanned_document' => true,
                'ocr_used' => false,
                'requires_manual_review' => true,
                'warning_code' => 'SCANNED_DOCUMENT_OCR_UNAVAILABLE',
                'warning_message' => 'Scanned or image-only PDF detected with no embedded text layer. Manual profile review is required.',
            ];
        }

        throw new RuntimeException('EMPTY_DOCUMENT: PDF document contains no extractable text content.', 422);
    }

    /**
     * @return array{text: string, is_scanned_document: bool, ocr_used: bool, requires_manual_review: bool, warning_code: ?string, warning_message: ?string}
     */
    public function extractFromDocxBytes(string $rawBytes): array
    {
        if (! str_starts_with($rawBytes, "PK\x03\x04")) {
            throw new RuntimeException('MALFORMED_DOCUMENT: DOCX file is not a valid ZIP container.', 422);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'urap_docx_');
        if ($tmpFile === false) {
            throw new RuntimeException('EXTRACTION_TEMP_ERROR: Unable to allocate temporary file for DOCX parsing.', 500);
        }

        try {
            file_put_contents($tmpFile, $rawBytes);
            $zip = new ZipArchive;
            if ($zip->open($tmpFile) !== true) {
                throw new RuntimeException('MALFORMED_DOCUMENT: Corrupted DOCX archive could not be opened.', 422);
            }

            $xmlContent = $zip->getFromName('word/document.xml');
            $zip->close();

            if (! is_string($xmlContent) || trim($xmlContent) === '') {
                throw new RuntimeException('MALFORMED_DOCUMENT: DOCX archive is missing word/document.xml.', 422);
            }

            $dom = new DOMDocument;
            $loaded = @$dom->loadXML($xmlContent, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            if (! $loaded) {
                throw new RuntimeException('MALFORMED_DOCUMENT: DOCX word/document.xml contains malformed XML.', 422);
            }

            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
            $paragraphs = $xpath->query('//w:p');
            $lines = [];

            if ($paragraphs !== false) {
                foreach ($paragraphs as $p) {
                    $runs = $xpath->query('.//w:t', $p);
                    $line = '';
                    if ($runs !== false) {
                        foreach ($runs as $r) {
                            $line .= $r->textContent;
                        }
                    }
                    $trimmed = trim($line);
                    if ($trimmed !== '') {
                        $lines[] = $trimmed;
                    }
                }
            }

            $text = $this->normalizeExtractedWhitespace(implode("\n", $lines));
            if ($text === '') {
                throw new RuntimeException('EMPTY_DOCUMENT: DOCX document contains no readable text.', 422);
            }

            return [
                'text' => $text,
                'is_scanned_document' => false,
                'ocr_used' => false,
                'requires_manual_review' => false,
                'warning_code' => null,
                'warning_message' => null,
            ];
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * @return array{text: string, is_scanned_document: bool, ocr_used: bool, requires_manual_review: bool, warning_code: ?string, warning_message: ?string}
     */
    public function extractFromLegacyDocBytes(string $rawBytes): array
    {
        $printable = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', ' ', $rawBytes) ?? '';
        $text = $this->normalizeExtractedWhitespace($printable);
        if (strlen($text) < 10) {
            throw new RuntimeException('EMPTY_DOCUMENT: DOC file contains no extractable text.', 422);
        }

        return [
            'text' => $text,
            'is_scanned_document' => false,
            'ocr_used' => false,
            'requires_manual_review' => false,
            'warning_code' => null,
            'warning_message' => null,
        ];
    }

    /**
     * @return array{text: string, is_scanned_document: bool, ocr_used: bool, requires_manual_review: bool, warning_code: ?string, warning_message: ?string}
     */
    public function extractFromPlainTextBytes(string $rawBytes): array
    {
        if (str_contains($rawBytes, "\x00")) {
            throw new RuntimeException('MALFORMED_DOCUMENT: Binary content detected inside plain-text file.', 422);
        }

        $text = $this->normalizeExtractedWhitespace($rawBytes);
        if ($text === '') {
            throw new RuntimeException('EMPTY_DOCUMENT: Text document contains no non-whitespace content.', 422);
        }

        return [
            'text' => $text,
            'is_scanned_document' => false,
            'ocr_used' => false,
            'requires_manual_review' => false,
            'warning_code' => null,
            'warning_message' => null,
        ];
    }

    private function extractPdfStreamsNative(string $rawBytes): string
    {
        $chunks = [];

        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $rawBytes, $matches)) {
            foreach ($matches[1] as $streamData) {
                $decoded = @gzuncompress($streamData);
                if ($decoded === false) {
                    $decoded = @gzinflate($streamData);
                }
                $content = is_string($decoded) ? $decoded : $streamData;
                $chunks[] = $this->extractTextOperatorsFromPdfContent($content);
            }
        }

        $chunks[] = $this->extractTextOperatorsFromPdfContent($rawBytes);

        return implode("\n", array_filter($chunks));
    }

    private function extractTextOperatorsFromPdfContent(string $content): string
    {
        $lines = [];

        if (preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)\s*Tj/s', $content, $tjMatches)) {
            foreach ($tjMatches[1] as $str) {
                $lines[] = $this->unescapePdfString($str);
            }
        }

        if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $tjArrayMatches)) {
            foreach ($tjArrayMatches[1] as $arrContent) {
                if (preg_match_all('/\(([^()\\\\]*(?:\\\\.[^()\\\\]*)*)\)/s', $arrContent, $subMatches)) {
                    $line = '';
                    foreach ($subMatches[1] as $part) {
                        $line .= $this->unescapePdfString($part);
                    }
                    if (trim($line) !== '') {
                        $lines[] = $line;
                    }
                }
            }
        }

        return implode("\n", $lines);
    }

    private function unescapePdfString(string $str): string
    {
        return strtr($str, [
            '\\n' => "\n",
            '\\r' => "\r",
            '\\t' => "\t",
            '\\(' => '(',
            '\\)' => ')',
            '\\\\' => '\\',
        ]);
    }

    private function extractPdfViaPopplerCli(string $rawBytes): string
    {
        $binaries = ['/snap/antigravity-cli/23/usr/bin/pdftotext', '/usr/bin/pdftotext'];
        $bin = null;
        foreach ($binaries as $candidateBin) {
            if (is_executable($candidateBin)) {
                $bin = $candidateBin;
                break;
            }
        }

        if ($bin === null) {
            return '';
        }

        $tmpPdf = tempnam(sys_get_temp_dir(), 'urap_pdf_');
        $tmpTxt = tempnam(sys_get_temp_dir(), 'urap_txt_');
        if ($tmpPdf === false || $tmpTxt === false) {
            return '';
        }

        try {
            file_put_contents($tmpPdf, $rawBytes);
            $cmd = escapeshellcmd($bin).' -enc UTF-8 -q '.escapeshellarg($tmpPdf).' '.escapeshellarg($tmpTxt).' 2>/dev/null';
            @exec($cmd);

            return is_file($tmpTxt) ? (string) file_get_contents($tmpTxt) : '';
        } finally {
            @unlink($tmpPdf);
            @unlink($tmpTxt);
        }
    }

    private function normalizeExtractedWhitespace(string $text): string
    {
        $utf8 = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $lines = preg_split('/\r\n|\r|\n/', $utf8) ?: [];
        $cleaned = [];
        foreach ($lines as $line) {
            $norm = trim((string) preg_replace('/[ \t]+/', ' ', $line));
            if ($norm !== '') {
                $cleaned[] = $norm;
            }
        }

        return implode("\n", $cleaned);
    }
}
