<?php
declare(strict_types=1);

require_once __DIR__ . '/workflow_helpers.php';

/**
 * Fit-to-Work Certificate, generated next to the Medical Certificate by
 * AdminStaff_MedicalDocuments.php and Nurse_Patients.php. Both pages define
 * the same PDF drawing helpers (drawSbiPdfHeader, pdfWriteText, ...), so this
 * file only adds the certificate layout and reuses them.
 */

const FIT_TO_WORK_DOCUMENT_TYPE = 'Fit-to-Work Certificate';

/**
 * medical_documents.document_type is an ENUM. Until
 * sources/migrate_fit_to_work_document.php is applied, a Fit-to-Work
 * certificate is stored as 'Other'; its document name still starts with
 * "Fit-to-Work Certificate - ", so it stays easy to find.
 */
function medicalDocumentStoredType(mysqli $conn, string $documentType): string
{
    static $allowed = null;
    if ($allowed === null) {
        $allowed = [];
        $stmt = $conn->prepare("SELECT COLUMN_TYPE FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'medical_documents' AND COLUMN_NAME = 'document_type'");
        if ($stmt) {
            $stmt->execute();
            $columnType = (string)($stmt->get_result()->fetch_assoc()['COLUMN_TYPE'] ?? '');
            $stmt->close();
            if (preg_match_all("/'((?:[^'\\\\]|\\\\.|'')*)'/", $columnType, $matches)) {
                $allowed = array_map(static fn($value) => str_replace("''", "'", $value), $matches[1]);
            }
        }
    }
    if (!$allowed || in_array($documentType, $allowed, true)) {
        return $documentType;
    }
    return 'Other';
}

function drawFitToWorkCertificate(&$stream, $data, $hasLogo = false)
{
    drawSbiPdfHeader($stream, $data, 'Fit-to-Work Certificate', $hasLogo);

    $blue = [0.04, 0.18, 0.50];
    $x = 42;
    $w = 511;
    $y = 682;
    $h = 20;

    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Certificate No.', $data['certificate_no'], 'Date Issued', $data['date_issued']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Patient Name', $data['patient_name'], 'Case No.', $data['case_number']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Date of Birth', $data['birthday'], 'Age', $data['age']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Sex', $data['sex'], 'Contact No.', $data['contact_number']);
    $y -= $h;
    drawPdfLabelValueRow($stream, $x, $y, $w, $h, 'Address', $data['address'], '', '');

    $y -= 22;
    $intro = 'This is to certify that the above-named patient was seen and managed at this clinic for an animal bite or rabies exposure, and received the treatment listed below.';
    foreach (pdfWrapText($intro, 6.9, $w) as $line) {
        pdfWriteText($stream, $x, $y, $line, 6.9);
        $y -= 9;
    }

    $y -= 22;
    drawSectionHeader($stream, $x, $y, $w, 'ANIMAL BITE / EXPOSURE DETAILS');
    $y -= 20;

    $detailRows = [
        ['Date of Bite/Exposure', $data['bite_date']],
        ['Animal', $data['animal_type']],
        ['Site of Bite', $data['bite_location']],
        ['Type / Category of Exposure', $data['bite_category']],
        ['Treatment Given', getTreatmentSummary($data)],
    ];
    foreach ($detailRows as $row) {
        pdfRect($stream, $x, $y, $w, 20, null, [0.60,0.65,0.74], 0.55);
        pdfRect($stream, $x, $y, 155, 20, [0.965,0.975,0.995], [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $x, $y, 155, 20, $row[0], 6.8, true);
        pdfCellText($stream, $x + 155, $y, $w - 155, 20, $row[1], 6.9);
        $y -= 20;
    }

    // Doses actually given, in the order they were given.
    $given = [];
    foreach ($data['vaccinations'] ?? [] as $v) {
        if (($v['vaccination_status'] ?? '') === 'Completed' && !empty($v['date_administered'])) {
            $given[] = $v;
        }
    }
    usort($given, static fn($a, $b) => strcmp((string)$a['date_administered'], (string)$b['date_administered']));

    $y -= 14;
    $cols = [120, 150, 241];
    $headers = ['DOSE', 'DATE GIVEN', 'VACCINE'];
    $cx = $x;
    foreach ($cols as $i => $cw) {
        pdfRect($stream, $cx, $y, $cw, 20, [0.94,0.96,0.99], [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $cx, $y, $cw, 20, $headers[$i], 6.3, true, 'center', $blue);
        $cx += $cw;
    }
    $y -= 20;
    if (!$given) {
        pdfRect($stream, $x, $y, $w, 19, null, [0.60,0.65,0.74], 0.55);
        pdfCellText($stream, $x, $y, $w, 19, 'No completed vaccination recorded for this case.', 6.6, false, 'center');
        $y -= 19;
    }
    // Shared patient-facing label per dose stage ("Dose 2 - D3"); several
    // products given on the same day share their stage label.
    $stageLabels = [];
    foreach ($data['dose_stages'] ?? [] as $stage) {
        $stageLabels[(int)$stage['dose_number']] = (string)$stage['dose_display'];
    }
    foreach (array_slice($given, 0, 6) as $v) {
        $doseNumber = (int)$v['dose_number'];
        $values = [
            $doseNumber <= 0 ? 'One-time' : ($stageLabels[$doseNumber] ?? workflowDoseLabel($doseNumber)),
            date('M d, Y', strtotime((string)$v['date_administered'])),
            (string)($v['vaccine_name'] ?? ''),
        ];
        $cx = $x;
        foreach ($cols as $i => $cw) {
            pdfRect($stream, $cx, $y, $cw, 19, null, [0.60,0.65,0.74], 0.55);
            pdfCellText($stream, $cx, $y, $cw, 19, $values[$i], 6.6, false, 'center');
            $cx += $cw;
        }
        $y -= 19;
    }

    $y -= 18;
    drawSectionHeader($stream, $x, $y, $w, 'CERTIFICATION');
    $y -= 18;
    $statement = 'Based on the assessment of the attending healthcare professional, the patient is FIT TO RETURN TO WORK / SCHOOL effective ' . $data['date_issued'] . '.';
    foreach (pdfWrapText($statement, 7.2, $w) as $line) {
        pdfWriteText($stream, $x, $y, $line, 7.2, true);
        $y -= 10;
    }
    if (!empty($data['next_schedule'])) {
        $y -= 2;
        $next = 'The patient must still complete the remaining vaccination schedule. Next dose: ' . date('F d, Y', strtotime((string)$data['next_schedule'])) . '.';
        foreach (pdfWrapText($next, 6.8, $w) as $line) {
            pdfWriteText($stream, $x, $y, $line, 6.8);
            $y -= 9;
        }
    }

    $y -= 10;
    pdfWriteText($stream, $x, $y, 'Remarks / Restrictions', 6.9, true);
    $remarksY = $y - 46;
    pdfRect($stream, $x, $remarksY, $w, 38, null, [0.60,0.65,0.74], 0.55);
    $remarks = $data['case_remarks'] !== '' ? $data['case_remarks'] : 'None.';
    $ry = $remarksY + 25;
    foreach (array_slice(pdfWrapText($remarks, 6.8, $w - 12), 0, 3) as $line) {
        pdfWriteText($stream, $x + 6, $ry, $line, 6.8);
        $ry -= 9;
    }

    drawSignatureArea($stream, 'ATTENDING HEALTHCARE PROFESSIONAL', 'CLINIC / AUTHORIZED SIGNATORY', 60);
    pdfLine($stream, 35, 49, 560, 49, 0.5, [0.65,0.68,0.74]);
    pdfWriteText($stream, 35, 38, 'This certificate is issued upon request of the patient for work or school purposes. Contents should be verified by authorized clinic personnel.', 5.3, false, [0.28,0.31,0.38]);
}
