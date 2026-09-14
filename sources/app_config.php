<?php
/**
 * SmartBiteCare public application URL.
 *
 * Priority:
 * 1. SMARTBITECARE_APP_URL (recommended for custom domains)
 * 2. Railway-provided RAILWAY_PUBLIC_DOMAIN
 * 3. Local XAMPP fallback
 */
$configuredAppUrl = trim((string)getenv('SMARTBITECARE_APP_URL'));

if ($configuredAppUrl === '') {
    $railwayDomain = trim((string)getenv('RAILWAY_PUBLIC_DOMAIN'));
    if ($railwayDomain !== '') {
        $configuredAppUrl = 'https://' . $railwayDomain;
    }
}

if ($configuredAppUrl === '') {
    $configuredAppUrl = 'http://localhost/SBI-ABC-SMARTBITECARE';
}

if (!defined('APP_URL')) {
    define('APP_URL', rtrim($configuredAppUrl, '/'));
}
?>
