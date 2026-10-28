<?php
declare(strict_types=1);

/*
 * Loads the shared styled dialogs (sources/confirm_modal.js) that replace the
 * browser's confirm(), alert() and prompt(), and the PDF Print button
 * (sources/print_pdf.js). Echo it once per page, after the
 * Bootstrap bundle:  <?php echo sbcConfirmModalScript(); ?>
 * The file's modification time is added so browsers pick up new versions.
 */
function sbcConfirmModalScript(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    $out = '';
    // The styled dialogs, and the Print button for PDFs (sources/print_pdf.js).
    foreach (['confirm_modal.js', 'print_pdf.js'] as $name) {
        $file = __DIR__ . '/' . $name;
        $version = is_file($file) ? (string)filemtime($file) : '1';
        $out .= '<script src="sources/' . $name . '?v=' . $version . '"></script>';
    }
    return $out;
}
