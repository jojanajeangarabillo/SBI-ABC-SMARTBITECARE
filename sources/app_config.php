<?php

/**
 * SmartBiteCare application URL.
 *
 * Local development:
 *     http://localhost/SBI-ABC-SMARTBITECARE
 *
 * Production example:
 *     https://smartbitecare.example.com
 *
 * On a production server, set the SMARTBITECARE_APP_URL environment variable
 * or replace the local fallback below with the final HTTPS domain.
 */
$configuredAppUrl = trim((string)getenv('SMARTBITECARE_APP_URL'));

if ($configuredAppUrl === '') {
    $configuredAppUrl = 'http://localhost/SBI-ABC-SMARTBITECARE';
}

if (!defined('APP_URL')) {
    define('APP_URL', rtrim($configuredAppUrl, '/'));
}

