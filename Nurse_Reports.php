<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';
require_once 'sources/report_center.php';
require_once 'sources/report_page_layout.php';

/*
 * NURSE REPORTS: Daily Inventory / Closing and the latest saved Supply Forecast,
 * on the shared report center (preview, Export PDF, Print). Read-only.
 */
$user = workflowRequireUser($conn, 3);
$reportCenter = reportCenterRun($conn, reportCenterCatalogEntries(3), [
    'user_id' => (int)$user['user_id'], 'role_id' => 3, 'branch_id' => (string)$user['branch_id'],
    'page' => 'Nurse_Reports.php', 'module' => 'Reports', 'link_base' => '',
]);
echo reportPageRender(['role_id' => 3] + $user, 'Reports', reportCenterRender($reportCenter, 'Generate a report'), (int)getUnreadNotificationCount($conn, (int)$user['user_id']));
