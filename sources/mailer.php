<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../PHPMailer-master/src/SMTP.php';

function send_email($to, $subject, $body) {
    $mail = new PHPMailer(true);

    $smtpHost = trim((string)getenv('SMARTBITECARE_SMTP_HOST')) ?: 'smtp.gmail.com';
    $smtpPort = (int)(trim((string)getenv('SMARTBITECARE_SMTP_PORT')) ?: '587');
    $smtpUser = trim((string)getenv('SMARTBITECARE_SMTP_USERNAME'));
    $smtpPass = (string)getenv('SMARTBITECARE_SMTP_PASSWORD');
    $fromEmail = trim((string)getenv('SMARTBITECARE_SMTP_FROM')) ?: $smtpUser;
    $fromName = trim((string)getenv('SMARTBITECARE_SMTP_FROM_NAME')) ?: 'SmartBiteCare';

    if ($smtpUser === '' || $smtpPass === '' || $fromEmail === '') {
        error_log('SmartBiteCare email is not configured. Set SMARTBITECARE_SMTP_USERNAME and SMARTBITECARE_SMTP_PASSWORD.');
        return false;
    }

    try {
        $mail->isSMTP();
        $mail->Host = $smtpHost;
        $mail->SMTPAuth = true;
        $mail->Username = $smtpUser;
        $mail->Password = $smtpPass;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $smtpPort;

        $mail->setFrom($fromEmail, $fromName);
        $mail->addAddress($to);

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('SmartBiteCare mail error: ' . $mail->ErrorInfo);
        return false;
    }
}
?>
