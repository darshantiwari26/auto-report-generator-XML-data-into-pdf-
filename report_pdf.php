<?php
session_start();
require_once __DIR__ . '/includes/report_helpers.php';

if (empty($_SESSION['report'])) {
    http_response_code(404);
    echo 'No generated report found. Please upload an XML file first.';
    exit;
}

$report = $_SESSION['report'];
$lines = [];
$lines[] = 'NIET AUTO REPORT GENERATOR';
$lines[] = 'Generated Report';
$lines[] = '';

foreach ($report['metrics'] as $label => $value) {
    $lines[] = $label . ': ' . $value;
}

if (!empty($report['attributes']) || !empty($report['overview'])) {
    $lines[] = '';
    $lines[] = 'DATASET OVERVIEW';
    foreach ($report['attributes'] as $label => $value) {
        $lines[] = '@' . $label . ': ' . $value;
    }
    foreach ($report['overview'] as $label => $value) {
        $lines[] = humanize($label) . ': ' . $value;
    }
}

foreach ($report['records'] as $group) {
    $lines[] = '';
    $lines[] = strtoupper($group['name']);
    $lines[] = str_repeat('-', 88);
    foreach ($group['rows'] as $index => $row) {
        $lines[] = 'Record ' . ($index + 1);
        foreach ($group['columns'] as $column) {
            $lines[] = '  ' . humanize($column) . ': ' . ($row[$column] ?? '');
        }
        $lines[] = '';
    }
}

outputSimplePdf($lines, 'niet-auto-report.pdf');

function outputSimplePdf(array $lines, $fileName)
{
    $objects = [];
    $pages = [];
    $lineHeight = 16;
    $top = 790;
    $bottom = 55;
    $pageLines = [];
    $current = [];
    $y = $top;

    foreach ($lines as $line) {
        foreach (wrapPdfLine($line, 92) as $wrapped) {
            if ($y < $bottom) {
                $pageLines[] = $current;
                $current = [];
                $y = $top;
            }
            $current[] = $wrapped;
            $y -= $lineHeight;
        }
    }
    $pageLines[] = $current;

    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = '';
    $fontObjectNumber = 3;
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    foreach ($pageLines as $pageIndex => $page) {
        $contentNumber = count($objects) + 2;
        $pageObjectNumber = count($objects) + 1;
        $pages[] = $pageObjectNumber . ' 0 R';
        $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 ' . $fontObjectNumber . ' 0 R >> >> /Contents ' . $contentNumber . ' 0 R >>';
        $objects[] = makePdfContent($page, $top, $lineHeight);
    }

    $objects[1] = '<< /Type /Pages /Kids [' . implode(' ', $pages) . '] /Count ' . count($pages) . ' >>';

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $index => $object) {
        $offsets[] = strlen($pdf);
        $number = $index + 1;
        if (substr($object, 0, 7) === 'stream:') {
            $stream = substr($object, 7);
            $pdf .= $number . " 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream\nendobj\n";
        } else {
            $pdf .= $number . " 0 obj\n" . $object . "\nendobj\n";
        }
    }

    $xref = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    foreach (array_slice($offsets, 1) as $offset) {
        $pdf .= sprintf('%010d 00000 n ', $offset) . "\n";
    }
    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\nstartxref\n" . $xref . "\n%%EOF";

    header('Content-Type: application/pdf');
    $disposition = isset($_GET['view']) && $_GET['view'] === '1' ? 'inline' : 'attachment';
    header('Content-Disposition: ' . $disposition . '; filename="' . $fileName . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
}

function wrapPdfLine($line, $width)
{
    $line = preg_replace('/[^\x20-\x7E]/', ' ', (string) $line);
    if ($line === '') {
        return [''];
    }
    return explode("\n", wordwrap($line, $width, "\n", true));
}

function makePdfContent(array $lines, $top, $lineHeight)
{
    $content = "BT\n/F1 10 Tf\n50 " . $top . " Td\n";
    foreach ($lines as $line) {
        $content .= '(' . escapePdfText($line) . ") Tj\n0 -" . $lineHeight . " Td\n";
    }
    $content .= "ET";
    return 'stream:' . $content;
}

function escapePdfText($text)
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}
