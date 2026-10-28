<?php
declare(strict_types=1);

/*
 * Loads the shared styled dialogs (sources/confirm_modal.js) that replace the
 * browser's confirm(), alert() and prompt(). Echo it once per page, after the
 * Bootstrap bundle:  <?php echo sbcConfirmModalScript(); ?>
 * The file's modification time is added so browsers pick up new versions.
 */
function sbcConfirmModalScript(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    $file = __DIR__ . '/confirm_modal.js';
    $version = is_file($file) ? (string)filemtime($file) : '1';
    return '<script src="sources/confirm_modal.js?v=' . $version . '"></script>';
}
