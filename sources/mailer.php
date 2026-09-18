<?php

/**
 * SmartBiteCare transactional email sender.
 *
 * Railway Free/Trial blocks outbound SMTP, so this implementation
 * sends email through Brevo's HTTPS API instead of PHPMailer/SMTP.
 *
 * Required Railway variables:
 *   BREVO_API_KEY
 *   SMARTBITECARE_EMAIL_FROM
 *
 * Optional:
 *   SMARTBITECARE_EMAIL_FROM_NAME
 */

function send_email($to, $subject, $body)
{
    $apiKey = trim((string)getenv('BREVO_API_KEY'));
    $fromEmail = trim((string)getenv('SMARTBITECARE_EMAIL_FROM'));
    $fromName = trim((string)getenv('SMARTBITECARE_EMAIL_FROM_NAME'));

    if ($fromName === '') {
        $fromName = 'SmartBiteCare';
    }

    // Validate configuration.
    if ($apiKey === '' || $fromEmail === '') {
        error_log(
            'SmartBiteCare email is not configured. '
            . 'Set BREVO_API_KEY and SMARTBITECARE_EMAIL_FROM.'
        );
        return false;
    }

    // Validate recipient and sender addresses.
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log(
            'SmartBiteCare email error: invalid recipient email address.'
        );
        return false;
    }

    if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
        error_log(
            'SmartBiteCare email error: invalid SMARTBITECARE_EMAIL_FROM address.'
        );
        return false;
    }

    $payload = [
        'sender' => [
            'name' => $fromName,
            'email' => $fromEmail
        ],
        'to' => [
            [
                'email' => $to
            ]
        ],
        'subject' => (string)$subject,
        'htmlContent' => (string)$body
    ];

    $jsonPayload = json_encode(
        $payload,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );

    if ($jsonPayload === false) {
        error_log(
            'SmartBiteCare email error: unable to encode email payload.'
        );
        return false;
    }

    $url = 'https://api.brevo.com/v3/smtp/email';

    /*
     * Preferred path: cURL.
     * This normally works in XAMPP and most PHP Docker/Railway setups.
     */
    if (function_exists('curl_init')) {
        $curl = curl_init($url);

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'api-key: ' . $apiKey,
                'content-type: application/json'
            ],
            CURLOPT_POSTFIELDS => $jsonPayload
        ]);

        $response = curl_exec($curl);

        if ($response === false) {
            $curlError = curl_error($curl);
            curl_close($curl);

            error_log(
                'SmartBiteCare Brevo request failed: '
                . $curlError
            );

            return false;
        }

        $statusCode = (int)curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

        curl_close($curl);

        if ($statusCode < 200 || $statusCode >= 300) {
            error_log(
                'SmartBiteCare Brevo API error. HTTP '
                . $statusCode
                . ' | Response: '
                . substr((string)$response, 0, 2000)
            );

            return false;
        }

        return true;
    }

    /*
     * Fallback path when the PHP cURL extension is unavailable.
     */
    $headers = [
        'accept: application/json',
        'api-key: ' . $apiKey,
        'content-type: application/json'
    ];

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $jsonPayload,
            'ignore_errors' => true,
            'timeout' => 30
        ]
    ]);

    $response = @file_get_contents(
        $url,
        false,
        $context
    );

    $statusCode = 0;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (
                preg_match(
                    '#^HTTP/\S+\s+(\d{3})#',
                    $headerLine,
                    $matches
                )
            ) {
                $statusCode = (int)$matches[1];
                break;
            }
        }
    }

    if (
        $response === false ||
        $statusCode < 200 ||
        $statusCode >= 300
    ) {
        error_log(
            'SmartBiteCare Brevo API fallback error. HTTP '
            . $statusCode
            . ' | Response: '
            . substr((string)$response, 0, 2000)
        );

        return false;
    }

    return true;
}
?>
