<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/notification_helper.php';
require_once 'sources/report_center.php';
require_once 'sources/report_page_layout.php';

/*
 * ADMIN STAFF REPORTS: PhilHealth Status and Data Quality (links open the record to fix),
 * on the shared report center (preview, Export PDF, Print). Read-only.
 */
$user = workflowRequireUser($conn, 4);
$reportCenter = reportCenterRun($conn, reportCenterCatalogEntries(4), [
    'user_id' => (int)$user['user_id'], 'role_id' => 4, 'branch_id' => (string)$user['branch_id'],
    'page' => 'AdminStaff_Reports.php', 'module' => 'Reports', 'link_base' => 'AdminStaff_PatientRecord.php',
]);
echo reportPageRender(['role_id' => 4] + $user, 'Reports', reportCenterRender($reportCenter, 'Generate a report'), (int)getAdminStaffNotificationCount($conn, (string)$user['branch_id'], (int)$user['user_id']));
