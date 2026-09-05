<?php
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/workflow_helpers.php';

$user=workflowRequireUser($conn,4);$userId=(int)$user['user_id'];$branchId=(string)$user['branch_id'];$csrf=workflowCsrfToken();
$branchName=(string)($user['branch_name']??$branchId);$username=(string)($user['username']??'Administrative Staff');
$caintaStatuses=['For Writing','For Screening','Ready for Main Branch','Sent to Main Branch','Returned for Correction'];

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        workflowVerifyCsrf();
        $recordId=(int)($_POST['record_id']??0);$newStatus=(string)($_POST['status']??'');$remarks=trim((string)($_POST['remarks']??''));
        if($recordId<1||!in_array($newStatus,$caintaStatuses,true))throw new RuntimeException('Choose a valid Cainta processing status.');
        $stmt=$conn->prepare("SELECT ph.status,p.full_name,c.case_number FROM philhealth_records ph INNER JOIN animal_bite_cases c ON c.case_id=ph.case_id INNER JOIN patients p ON p.patient_id=c.patient_id WHERE ph.philhealth_record_id=? AND c.branch_id=? AND ph.has_philhealth='Yes' AND ph.is_archived=0 LIMIT 1");
        $stmt->bind_param('is',$recordId,$branchId);$stmt->execute();$record=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$record)throw new RuntimeException('PhilHealth record was not found in your branch.');
        $oldStatus=$record['status'];
        $conn->begin_transaction();
        $sentDate=$newStatus==='Sent to Main Branch'?date('Y-m-d'):null;
        $returnedDate=$newStatus==='Returned for Correction'?date('Y-m-d'):null;
        $update=$conn->prepare("UPDATE philhealth_records SET status=?,remarks=?,date_sent_to_main=COALESCE(?,date_sent_to_main),date_returned=COALESCE(?,date_returned),service_date=COALESCE(service_date,CURDATE()),last_philhealth_use_date=COALESCE(last_philhealth_use_date,CURDATE()),eligible_again_on=COALESCE(eligible_again_on,DATE_ADD(CURDATE(),INTERVAL 6 MONTH)),updated_by=?,updated_at=NOW() WHERE philhealth_record_id=?");
        $update->bind_param('ssssii',$newStatus,$remarks,$sentDate,$returnedDate,$userId,$recordId);$update->execute();$update->close();
        $history=$conn->prepare('INSERT INTO philhealth_status_history(philhealth_record_id,old_status,new_status,remarks,changed_by) VALUES(?,?,?,?,?)');
        $history->bind_param('isssi',$recordId,$oldStatus,$newStatus,$remarks,$userId);$history->execute();$history->close();
        workflowAudit($conn,$userId,$branchId,'Changed PhilHealth status for '.$record['full_name'].' to '.$newStatus,'PhilHealth');$conn->commit();workflowFlash('success','PhilHealth status updated and recorded in history.');
    }catch(Throwable $e){try{$conn->rollback();}catch(Throwable $ignored){}workflowFlash('danger',$e->getMessage());}
    header('Location: AdminStaff_PhilhealthWorkflow.php');exit;
}

$stmt=$conn->prepare("SELECT ph.*,p.full_name,p.contact_number,c.case_number FROM philhealth_records ph INNER JOIN animal_bite_cases c ON c.case_id=ph.case_id INNER JOIN patients p ON p.patient_id=c.patient_id WHERE c.branch_id=? AND ph.has_philhealth='Yes' AND ph.is_archived=0 ORDER BY FIELD(ph.status,'Returned for Correction','For Writing','For Screening','Ready for Main Branch','Sent to Main Branch'),ph.updated_at DESC");
$stmt->bind_param('s',$branchId);$stmt->execute();$records=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();

$actionRequiredCount=0;$readyCount=0;$sentCount=0;
foreach($records as $record){
    $recordStatus=(string)($record['status']??'For Writing');
    if(in_array($recordStatus,['Returned for Correction','For Writing','For Screening'],true))$actionRequiredCount++;
    if($recordStatus==='Ready for Main Branch')$readyCount++;
    if($recordStatus==='Sent to Main Branch')$sentCount++;
}

function philhealthStatusClass(string $status):string{
    $classes=[
        'Returned for Correction'=>'status-danger','For Writing'=>'status-warning',
        'For Screening'=>'status-info','Ready for Main Branch'=>'status-primary',
        'Sent to Main Branch'=>'status-success','Submitted to PhilHealth'=>'status-primary',
        'Denied'=>'status-danger','Reimbursed'=>'status-success','Completed'=>'status-success'
    ];
    return $classes[$status]??'status-secondary';
}

$flash=workflowTakeFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>PhilHealth Workflow - SmartBiteCare</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="sidebar.css">
    <style>
        :root{--primary:#2B3A8C;--accent:#F21D2F;--success:#28a745;--warning:#ffc107;--danger:#dc3545;--info:#17a2b8}
        *{box-sizing:border-box}
        body{margin:0;background:#f0f2f5;font-family:'Segoe UI',sans-serif}
        .main{min-height:100vh;margin-left:260px;background:#f0f2f5}
        .topbar{display:flex;align-items:center;justify-content:space-between;height:80px;padding:0 35px;background:#fff;box-shadow:0 2px 8px rgba(0,0,0,.08)}
        .topbar h3{margin:0;color:var(--primary);font-size:28px;font-weight:700}
        .topbar h3 small{margin-left:8px;color:#6c757d;font-size:16px;font-weight:400}
        .profile{display:flex;align-items:center;gap:6px;color:var(--primary);font-weight:600}
        .profile-role{margin-left:4px;color:#adb5bd;font-size:12px}
        .page-content{padding:30px 35px}
        .stats-container{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:20px;margin-bottom:25px}
        .stat-card{position:relative;overflow:hidden;min-height:150px;padding:22px;background:#fff;border-left:5px solid var(--primary);border-radius:14px;box-shadow:0 4px 16px rgba(43,58,140,.08)}
        .stat-card.warning{border-left-color:var(--warning)}.stat-card.info{border-left-color:var(--info)}.stat-card.success{border-left-color:var(--success)}
        .stat-card h6{margin:0 0 8px;color:#697386;font-size:13px;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
        .stat-card h2{margin:0;color:#25324b;font-size:32px;font-weight:750}.stat-card p{margin:8px 0 0;color:#98a2b3;font-size:12px}
        .stat-icon{position:absolute;top:22px;right:20px;color:rgba(43,58,140,.15);font-size:37px}
        .stat-card.warning .stat-icon{color:rgba(255,193,7,.25)}.stat-card.info .stat-icon{color:rgba(23,162,184,.2)}.stat-card.success .stat-icon{color:rgba(40,167,69,.2)}
        .alert{border:0;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
        .scope-notice{display:flex;align-items:flex-start;gap:12px;margin-bottom:24px;padding:16px 18px;color:#34526f;background:#eaf6fb;border:1px solid #cceaf4;border-radius:12px}
        .scope-notice>i{color:var(--info);font-size:21px}.scope-notice strong{display:block;margin-bottom:2px;color:#234664}.scope-notice p{margin:0;font-size:13px;line-height:1.5}
        .content-card{overflow:hidden;background:#fff;border:0;border-radius:14px;box-shadow:0 4px 16px rgba(43,58,140,.08)}
        .content-card-header{display:flex;align-items:center;justify-content:space-between;gap:20px;padding:22px 24px;border-bottom:1px solid #edf0f5}
        .content-card-header h5{display:flex;align-items:center;gap:10px;margin:0;color:var(--primary);font-size:18px;font-weight:700}
        .content-card-header p{margin:6px 0 0;color:#8b95a7;font-size:13px}
        .section-icon{display:inline-flex;align-items:center;justify-content:center;width:34px;height:34px;color:#fff;background:var(--primary);border-radius:9px}
        .philhealth-table{min-width:1060px;margin:0}
        .philhealth-table thead th{padding:14px 18px;color:#667085;background:#f8f9fc;border-bottom:1px solid #e6eaf0;font-size:12px;font-weight:700;letter-spacing:.25px;text-transform:uppercase;white-space:nowrap}
        .philhealth-table tbody td{padding:18px;color:#4c566a;border-color:#edf0f4;font-size:13px;vertical-align:top}.philhealth-table tbody tr:hover{background:#fbfcff}
        .patient-name{color:#25324b;font-size:14px;font-weight:700}.case-number{display:inline-block;margin-top:4px;color:var(--primary);font-size:12px;font-weight:600}
        .detail-label{display:block;margin-bottom:3px;color:#98a2b3;font-size:10px;font-weight:700;letter-spacing:.3px;text-transform:uppercase}
        .badge-status{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap}
        .status-warning{color:#8a6300;background:#fff3cd}.status-info{color:#0c6876;background:#d7f3f7}.status-primary{color:#26377f;background:#e4e8fb}
        .status-success{color:#1f7a35;background:#ddf3e3}.status-danger{color:#a22632;background:#fde2e5}.status-secondary{color:#5e6878;background:#edf0f4}
        .status-remarks{max-width:220px;margin-top:8px;color:#7d8798;font-size:12px;line-height:1.4}
        .eligibility-date{color:#25324b;font-weight:700}.eligibility-note{display:flex;align-items:flex-start;gap:5px;max-width:220px;margin-top:6px;font-size:11px;line-height:1.4}
        .eligibility-note.waiting{color:#b54708}.eligibility-note.reached{color:#1f7a35}
        .workflow-form{min-width:430px;padding:12px;background:#f8f9fc;border:1px solid #e8ebf2;border-radius:10px}
        .workflow-form .form-label{margin-bottom:5px;color:#596579;font-size:11px;font-weight:700}
        .workflow-form .form-select,.workflow-form .form-control{min-height:37px;border-color:#dfe3eb;border-radius:8px;font-size:12px}
        .workflow-form .form-select:focus,.workflow-form .form-control:focus{border-color:#8c98d1;box-shadow:0 0 0 .2rem rgba(43,58,140,.1)}
        .btn-save{display:inline-flex;align-items:center;justify-content:center;gap:6px;min-height:37px;padding:7px 13px;color:#fff;background:var(--primary);border:1px solid var(--primary);border-radius:8px;font-size:12px;font-weight:700}
        .btn-save:hover{color:#fff;background:#202d70;border-color:#202d70}
        .read-only-box{display:flex;align-items:flex-start;gap:9px;min-width:260px;padding:12px 14px;color:#667085;background:#f6f7f9;border:1px solid #e5e8ed;border-radius:10px;font-size:12px;line-height:1.45}
        .read-only-box i{color:#8992a3;font-size:16px}.empty-state{padding:52px 20px!important;color:#98a2b3!important;text-align:center}.empty-state i{display:block;margin-bottom:8px;font-size:36px}
        @media(max-width:1199px){.stats-container{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:991px){.main{margin-left:90px}}
        @media(max-width:767px){.topbar{height:70px;padding:0 16px}.topbar h3{font-size:20px}.topbar h3 small,.profile-role{display:none}.page-content{padding:20px 16px}.stats-container{grid-template-columns:1fr}.content-card-header{align-items:flex-start;flex-direction:column}}
    </style>
</head>
<body>
    <div class="sidebar">
        <div class="logo-area">
            <div class="logo-frame"><img src="logo.png" alt="Smart Bite Care Logo" style="max-width:50px;height:auto;"></div>
            <div class="system-name">Smart Bite Care</div>
        </div>
        <nav class="nav-menu">
            <ul>
                <li><a href="AdminStaff_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
                <li><a href="AdminStaff_Calendar.php"><i class="bi bi-calendar-fill"></i><span>Calendar</span></a></li>
                <li><a href="AdminStaff_PatientRecord.php"><i class="bi bi-people-fill"></i><span>Patient Record Management</span></a></li>
                <li><a href="AdminStaff_VisitQueue.php"><i class="bi bi-person-check-fill"></i><span>Visit Check-in</span></a></li>
                <li><a href="AdminStaff_Registry.php"><i class="bi bi-journal-check"></i><span>Registry Queue</span></a></li>
                <li><a class="active" href="AdminStaff_PhilhealthWorkflow.php"><i class="bi bi-check2-all"></i><span>PhilHealth Workflow</span></a></li>
                <li><a href="AdminStaff_MedicalDocuments.php"><i class="bi bi-file-earmark-ruled"></i><span>Medical Documents</span></a></li>
                <li><a href="AdminStaff_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
            </ul>
        </nav>
        <div class="logout"><a href="logout.php"><i class="bi bi-box-arrow-right"></i><span>Logout</span></a></div>
    </div>

    <div class="main">
        <div class="topbar">
            <h3>PhilHealth Workflow <small><?php echo workflowH($branchName); ?></small></h3>
            <div class="profile"><i class="bi bi-person-circle"></i><span><?php echo workflowH($username); ?></span><span class="profile-role">| Administrative Staff</span></div>
        </div>

        <div class="page-content">
            <?php if($flash):?>
                <div class="alert alert-<?php echo workflowH($flash['type']); ?> alert-dismissible fade show" role="alert">
                    <?php echo workflowH($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif;?>

            <div class="stats-container">
                <div class="stat-card"><i class="bi bi-clipboard2-pulse-fill stat-icon"></i><h6>Total PhilHealth Records</h6><h2><?php echo number_format(count($records)); ?></h2><p>Active records for this branch</p></div>
                <div class="stat-card warning"><i class="bi bi-exclamation-circle-fill stat-icon"></i><h6>Action Required</h6><h2><?php echo number_format($actionRequiredCount); ?></h2><p>For writing, screening, or correction</p></div>
                <div class="stat-card info"><i class="bi bi-box-arrow-up-right stat-icon"></i><h6>Ready for Main Branch</h6><h2><?php echo number_format($readyCount); ?></h2><p>Prepared for document handoff</p></div>
                <div class="stat-card success"><i class="bi bi-send-check-fill stat-icon"></i><h6>Sent to Main Branch</h6><h2><?php echo number_format($sentCount); ?></h2><p>Initial processing completed</p></div>
            </div>

            <div class="scope-notice">
                <i class="bi bi-info-circle-fill"></i>
                <div><strong>Administrative Staff processing scope</strong><p>You may prepare, screen, and record the handoff of PhilHealth documents. Submitted to PhilHealth, Denied, Reimbursed, and Completed are controlled by the main branch and remain read-only here.</p></div>
            </div>

            <section class="content-card">
                <div class="content-card-header">
                    <div><h5><span class="section-icon"><i class="bi bi-file-medical-fill"></i></span>PhilHealth Initial Processing</h5><p>Prepare, screen, and track eligible patient records before main-branch processing.</p></div>
                    <span class="badge rounded-pill text-bg-light border"><?php echo number_format(count($records)); ?> records</span>
                </div>
                <div class="table-responsive">
                    <table class="table philhealth-table align-middle">
                        <thead><tr><th>Patient and Case</th><th>Current Status</th><th>Eligibility</th><th>Update Processing Step</th></tr></thead>
                        <tbody>
                            <?php if(!$records):?><tr><td colspan="4" class="empty-state"><i class="bi bi-inbox"></i>No active PhilHealth records were found for this branch.</td></tr><?php endif;?>
                            <?php foreach($records as $r):?>
                                <?php
                                $currentStatus=(string)($r['status']??'For Writing');
                                $canEdit=in_array($currentStatus,$caintaStatuses,true);
                                $eligibilityDate=!empty($r['eligible_again_on'])?(string)$r['eligible_again_on']:'';
                                $eligibilityTimestamp=$eligibilityDate!==''?strtotime($eligibilityDate):false;
                                $waitingPeriodActive=$eligibilityTimestamp!==false&&$eligibilityTimestamp>time();
                                ?>
                                <tr>
                                    <td>
                                        <div class="patient-name"><?php echo workflowH($r['full_name']); ?></div>
                                        <div class="case-number"><?php echo workflowH($r['case_number']); ?></div>
                                        <?php if(!empty($r['contact_number'])):?><div class="text-muted small mt-2"><i class="bi bi-telephone me-1"></i><?php echo workflowH($r['contact_number']); ?></div><?php endif;?>
                                        <?php if(!empty($r['service_date'])):?><div class="text-muted small mt-1"><i class="bi bi-calendar3 me-1"></i>Service: <?php echo workflowH(date('M d, Y',strtotime($r['service_date']))); ?></div><?php endif;?>
                                    </td>
                                    <td>
                                        <span class="badge-status <?php echo workflowH(philhealthStatusClass($currentStatus)); ?>"><?php echo workflowH($currentStatus); ?></span>
                                        <?php if(!empty($r['remarks'])):?><div class="status-remarks"><span class="detail-label">Latest Remarks</span><?php echo workflowH($r['remarks']); ?></div><?php endif;?>
                                        <?php if(!empty($r['date_sent_to_main'])):?><div class="text-muted small mt-2">Sent: <?php echo workflowH(date('M d, Y',strtotime($r['date_sent_to_main']))); ?></div><?php endif;?>
                                    </td>
                                    <td>
                                        <?php if($eligibilityTimestamp!==false):?>
                                            <span class="detail-label">Eligible Again On</span><div class="eligibility-date"><?php echo workflowH(date('M d, Y',$eligibilityTimestamp)); ?></div>
                                            <div class="eligibility-note <?php echo $waitingPeriodActive?'waiting':'reached'; ?>"><i class="bi <?php echo $waitingPeriodActive?'bi-hourglass-split':'bi-check-circle-fill'; ?>"></i><span><?php echo $waitingPeriodActive?'Waiting period is active.':'Eligibility date reached; staff verification is still required.'; ?></span></div>
                                        <?php else:?><span class="text-muted">Not yet calculated</span><?php endif;?>
                                    </td>
                                    <td>
                                        <?php if($canEdit):?>
                                            <form method="post" class="workflow-form">
                                                <input type="hidden" name="csrf_token" value="<?php echo workflowH($csrf); ?>">
                                                <input type="hidden" name="record_id" value="<?php echo (int)$r['philhealth_record_id']; ?>">
                                                <div class="row g-2">
                                                    <div class="col-md-5"><label class="form-label" for="status-<?php echo (int)$r['philhealth_record_id']; ?>">Processing Status</label><select class="form-select form-select-sm" id="status-<?php echo (int)$r['philhealth_record_id']; ?>" name="status" required><?php foreach($caintaStatuses as $status):?><option value="<?php echo workflowH($status); ?>" <?php echo $currentStatus===$status?'selected':''; ?>><?php echo workflowH($status); ?></option><?php endforeach;?></select></div>
                                                    <div class="col-md-5"><label class="form-label" for="remarks-<?php echo (int)$r['philhealth_record_id']; ?>">Remarks / Document Handoff</label><input class="form-control form-control-sm" id="remarks-<?php echo (int)$r['philhealth_record_id']; ?>" name="remarks" maxlength="1000" value="<?php echo workflowH($r['remarks']??''); ?>" placeholder="Add processing remarks"></div>
                                                    <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn-save w-100" onclick="return confirm('Save this PhilHealth processing update?');"><i class="bi bi-check2-circle"></i>Save</button></div>
                                                </div>
                                            </form>
                                        <?php else:?>
                                            <div class="read-only-box"><i class="bi bi-lock-fill"></i><span>This status is controlled by the main branch and cannot be changed by Administrative Staff.</span></div>
                                        <?php endif;?>
                                    </td>
                                </tr>
                            <?php endforeach;?>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
