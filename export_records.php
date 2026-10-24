<?php
require_once 'auth.php';
require_once 'db.php';
require_role(['patient']);

$stmt = $pdo->prepare('SELECT id FROM patients WHERE user_id = ?');
$stmt->execute([$_SESSION['user_id']]);
$patient = $stmt->fetch();

if (!$patient) {
    http_response_code(404);
    die('No patient profile found for this account.');
}

$stmt = $pdo->prepare(
    "SELECT mr.title, mr.notes, mr.record_date, mr.status, u.full_name AS doctor_name
     FROM medical_records mr
     JOIN doctors d ON d.id = mr.doctor_id
     JOIN users u ON u.id = d.user_id
     WHERE mr.patient_id = ?
     ORDER BY mr.record_date DESC"
);
$stmt->execute([$patient['id']]);
$records = $stmt->fetchAll();

function pdf_escape(string $text): string
{
    if (function_exists('iconv')) {
        $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted !== false) {
            $text = $converted;
        } else {
            $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
        }
    } else {
        $text = preg_replace('/[^\x20-\x7E]/', '?', $text);
    }

    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

function pdf_wrap(string $text, int $width = 88): array
{
    $paragraphs = preg_split('/\r\n|\r|\n/', $text);
    $lines = [];

    foreach ($paragraphs as $paragraph) {
        $wrapped = wordwrap($paragraph, $width, "\n", true);
        array_push($lines, ...explode("\n", $wrapped));
    }

    return $lines ?: [''];
}

$pages = [[]];
$y = 790;
$addLine = static function (string $text, string $font = 'F1', int $size = 11) use (&$pages, &$y): void {
    if ($y < 55) {
        $pages[] = [];
        $y = 790;
    }

    $pages[count($pages) - 1][] = [$text, $font, $size, $y];
    $y -= max(14, (int) ceil($size * 1.5));
};
$addWrapped = static function (string $text, string $font = 'F1', int $size = 11) use ($addLine): void {
    foreach (pdf_wrap($text) as $line) {
        $addLine($line, $font, $size);
    }
};

$addLine('Medical Records', 'F2', 20);
$addWrapped('Patient: ' . ($_SESSION['full_name'] ?? 'Patient'));
$addWrapped('Exported: ' . date('d M Y'));
$addLine('');

if (!$records) {
    $addWrapped('No medical records are available.');
} else {
    foreach ($records as $index => $record) {
        $addWrapped(($index + 1) . '. ' . $record['title'], 'F2', 12);
        $addWrapped('Date: ' . date('d M Y', strtotime($record['record_date'])) . '    Physician: ' . $record['doctor_name']);
        $addWrapped('Status: ' . $record['status']);
        $addWrapped('Notes: ' . ($record['notes'] ?: 'No notes provided.'));
        $addLine('');
    }
}

$pageCount = count($pages);
$fontRegularObject = 3 + $pageCount * 2;
$fontBoldObject = $fontRegularObject + 1;
$objects = [];
$kids = [];

foreach ($pages as $pageIndex => $lines) {
    $pageObject = 3 + $pageIndex * 2;
    $contentObject = $pageObject + 1;
    $kids[] = $pageObject . ' 0 R';

    $content = '';
    foreach ($lines as [$text, $font, $size, $lineY]) {
        $content .= sprintf(
            "BT /%s %d Tf 50 %d Td (%s) Tj ET\n",
            $font,
            $size,
            $lineY,
            pdf_escape($text)
        );
    }
    $content .= sprintf(
        "BT /F1 9 Tf 500 25 Td (Page %d of %d) Tj ET\n",
        $pageIndex + 1,
        $pageCount
    );

    $objects[$pageObject] = sprintf(
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 %d 0 R /F2 %d 0 R >> >> /Contents %d 0 R >>',
        $fontRegularObject,
        $fontBoldObject,
        $contentObject
    );
    $objects[$contentObject] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . 'endstream';
}

$objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
$objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
$objects[$fontRegularObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
$objects[$fontBoldObject] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
ksort($objects);

$pdf = "%PDF-1.4\n";
$offsets = [0];
foreach ($objects as $objectNumber => $object) {
    $offsets[$objectNumber] = strlen($pdf);
    $pdf .= $objectNumber . " 0 obj\n" . $object . "\nendobj\n";
}

$xrefOffset = strlen($pdf);
$objectCount = count($objects) + 1;
$pdf .= "xref\n0 " . $objectCount . "\n0000000000 65535 f \n";
for ($objectNumber = 1; $objectNumber < $objectCount; $objectNumber++) {
    $pdf .= sprintf("%010d 00000 n \n", $offsets[$objectNumber]);
}
$pdf .= "trailer\n<< /Size " . $objectCount . " /Root 1 0 R >>\nstartxref\n" . $xrefOffset . "\n%%EOF";

$patientName = $_SESSION['full_name'] ?? 'Patient';
$safePatientName = preg_replace('/[^a-z0-9_-]+/i', '_', $patientName);
$safePatientName = trim($safePatientName, '_') ?: 'Patient';
$filename = 'Medical_Records_' . $safePatientName . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
echo $pdf;
