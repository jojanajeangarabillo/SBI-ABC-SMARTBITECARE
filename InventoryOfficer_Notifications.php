<?php
session_start();
require_once 'sources/db_connect.php';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || (int)$_SESSION['role_id'] !== 5) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = 'Inventory Officer';
$branch_id = null;
$branch_name = 'No Branch Assigned';

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function jsonResponse(bool $success, string $message = '', array $extra = [], int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success'=>$success,'message'=>$message], $extra));
    exit();
}
function notificationTypeClass(string $type): string {
    return match ($type) {
        'low_stock','critical_stock' => 'low_stock',
        'expiring','expired_stock' => 'expiring',
        'prediction','shortage_prediction' => 'prediction',
        'stock_in' => 'stock_in',
        'stock_out' => 'stock_out',
        'stock_adjustment' => 'stock_adjustment',
        default => 'low_stock'
    };
}
function notifIconClass(string $type): string {
    return match (notificationTypeClass($type)) {
        'low_stock' => 'icon-low', 'expiring' => 'icon-expiring', 'prediction' => 'icon-prediction',
        'stock_in' => 'icon-in', 'stock_out' => 'icon-out', 'stock_adjustment' => 'icon-adjustment',
        default => 'icon-low'
    };
}
function notifIcon(string $type): string {
    return match (notificationTypeClass($type)) {
        'low_stock' => 'bi-exclamation-triangle-fill', 'expiring' => 'bi-hourglass-split',
        'prediction' => 'bi-graph-up-arrow', 'stock_in' => 'bi-box-arrow-in-down',
        'stock_out' => 'bi-box-arrow-up', 'stock_adjustment' => 'bi-sliders', default => 'bi-bell-fill'
    };
}
function formatNotificationTime(string $createdAt): string {
    $timestamp = strtotime($createdAt);
    if ($timestamp === false) return $createdAt;
    $date = date('Y-m-d', $timestamp);
    if ($date === date('Y-m-d')) return 'Today, '.date('g:i A',$timestamp);
    if ($date === date('Y-m-d',strtotime('-1 day'))) return 'Yesterday, '.date('g:i A',$timestamp);
    return date('M j, Y g:i A',$timestamp);
}
function badgeText(string $type): string {
    return match (notificationTypeClass($type)) {
        'low_stock' => 'Low Stock', 'expiring' => 'Expiring', 'prediction' => 'Alert',
        'stock_in' => 'Stock In', 'stock_out' => 'Stock Out', 'stock_adjustment' => 'Adjusted', default => 'Update'
    };
}
function bindDynamic(mysqli_stmt $stmt, string $types, array &$params): void {
    $refs = [];
    $refs[] = $types;
    foreach ($params as $k => &$v) $refs[] = &$v;
    call_user_func_array([$stmt,'bind_param'],$refs);
}

$userSql = "SELECT u.branch_id,u.username,b.branch_name FROM users u LEFT JOIN branches b ON b.branch_id=u.branch_id WHERE u.user_id=? AND u.status='Active' LIMIT 1";
$userStmt = $conn->prepare($userSql);
if (!$userStmt) { http_response_code(500); die('Database error: Unable to prepare user query.'); }
$userStmt->bind_param('i',$user_id);
if (!$userStmt->execute()) { $userStmt->close(); http_response_code(500); die('Database error: Unable to retrieve user information.'); }
$userResult = $userStmt->get_result();
$userStmt->close();
if ($userResult->num_rows !== 1) { session_unset(); session_destroy(); header('Location: login.php'); exit(); }
$userData=$userResult->fetch_assoc();
$branch_id=$userData['branch_id']; $username=$userData['username'] ?: 'Inventory Officer'; $branch_name=$userData['branch_name'] ?: 'No Branch Assigned';
if ($branch_id===null || $branch_id==='') { http_response_code(403); die('Your account is not assigned to a branch.'); }

if (empty($_SESSION['notifications_csrf'])) $_SESSION['notifications_csrf']=bin2hex(random_bytes(32));
$csrf_token=$_SESSION['notifications_csrf'];

/* AJAX/read actions. Every update is scoped to the authenticated user and authenticated branch. */
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($csrf_token,(string)($_POST['csrf_token']??''))) jsonResponse(false,'Invalid security token.',[],403);
    $action=(string)($_POST['action']??'');
    if ($action==='mark_read') {
        $notification_id=filter_var($_POST['notification_id']??null,FILTER_VALIDATE_INT);
        if ($notification_id===false || $notification_id===null || $notification_id<=0) jsonResponse(false,'Invalid notification ID.',[],400);
        $sql="UPDATE notifications n INNER JOIN users u ON u.user_id=n.user_id SET n.is_read=1 WHERE n.notification_id=? AND n.user_id=? AND u.user_id=? AND u.branch_id=? AND u.status='Active'";
        $stmt=$conn->prepare($sql);
        if (!$stmt) jsonResponse(false,'Unable to prepare notification update.',[],500);
        $stmt->bind_param('iiis',$notification_id,$user_id,$user_id,$branch_id);
        if (!$stmt->execute()) { $stmt->close(); jsonResponse(false,'Unable to update notification.',[],500); }
        $stmt->close();
        $verify=$conn->prepare("SELECT n.is_read FROM notifications n INNER JOIN users u ON u.user_id=n.user_id WHERE n.notification_id=? AND n.user_id=? AND u.branch_id=? LIMIT 1");
        $isRead=false; $exists=false;
        if ($verify) { $verify->bind_param('iis',$notification_id,$user_id,$branch_id); if($verify->execute()){ $r=$verify->get_result(); if($r->num_rows===1){$exists=true;$isRead=(int)$r->fetch_assoc()['is_read']===1;}} $verify->close(); }
        if (!$exists) jsonResponse(false,'Notification not found.',[],404);
        $c=$conn->prepare("SELECT COUNT(*) unread_count FROM notifications n INNER JOIN users u ON u.user_id=n.user_id WHERE n.user_id=? AND u.branch_id=? AND n.is_read=0");
        $unread=0;
        if($c){$c->bind_param('is',$user_id,$branch_id);if($c->execute())$unread=(int)($c->get_result()->fetch_assoc()['unread_count']??0);$c->close();}
        jsonResponse(true,'Notification marked as read.',['notification_id'=>$notification_id,'is_read'=>$isRead,'unread_count'=>$unread]);
    }
    if ($action==='mark_all_read') {
        $stmt=$conn->prepare("UPDATE notifications n INNER JOIN users u ON u.user_id=n.user_id SET n.is_read=1 WHERE n.user_id=? AND u.branch_id=? AND u.status='Active'");
        if(!$stmt)jsonResponse(false,'Unable to prepare mark-all action.',[],500);
        $stmt->bind_param('is',$user_id,$branch_id);
        if(!$stmt->execute()){ $stmt->close(); jsonResponse(false,'Unable to mark notifications as read.',[],500); }
        $stmt->close();
        jsonResponse(true,'All notifications have been marked as read.',['unread_count'=>0]);
    }
    jsonResponse(false,'Unsupported notification action.',[],400);
}

/*
 * Notification source tracking.
 * source_key is unique per user and represents the real inventory event/condition.
 * This prevents page refreshes from creating another copy of the same event.
 */
function saveNotification(mysqli $conn,int $userId,string $type,string $title,string $message,string $sourceKey): void {
    $sql="INSERT INTO notifications (user_id,title,message,notification_type,source_key,is_read) VALUES (?,?,?,?,?,0)
          ON DUPLICATE KEY UPDATE title=VALUES(title), message=VALUES(message), notification_type=VALUES(notification_type)";
    $stmt=$conn->prepare($sql);
    if(!$stmt)return;
    $stmt->bind_param('issss',$userId,$title,$message,$type,$sourceKey);
    $stmt->execute(); $stmt->close();
}

/*
 * Remove legacy notifications created by the old page before source_key existed.
 * They cannot be mapped reliably to a real transaction/condition, so keeping
 * them would mix stale duplicates with the synchronized notifications below.
 * Other notification types (vaccination, patient records, etc.) are untouched.
 */
$generatedTypes=['low_stock','critical_stock','expiring','expired_stock','prediction','shortage_prediction','stock_in','stock_out','stock_adjustment'];
$typePlaceholders=implode(',',array_fill(0,count($generatedTypes),'?'));
$cleanupSql="DELETE n FROM notifications n INNER JOIN users u ON u.user_id=n.user_id WHERE n.user_id=? AND u.branch_id=? AND u.status='Active' AND n.source_key IS NULL AND n.notification_type IN ($typePlaceholders)";
$cleanup=$conn->prepare($cleanupSql);
if($cleanup){$params=array_merge([$user_id,$branch_id],$generatedTypes);$types='is'.str_repeat('s',count($generatedTypes));bindDynamic($cleanup,$types,$params);$cleanup->execute();$cleanup->close();}

/* LOW/CRITICAL STOCK: one current condition alert per item. */
$sql="SELECT i.item_id,i.item_name,i.minimum_stock,u.unit_name,COALESCE(SUM(s.quantity_available),0) current_stock FROM inventory_items i INNER JOIN units u ON u.unit_id=i.unit_id LEFT JOIN inventory_stocks s ON s.item_id=i.item_id AND s.branch_id=? GROUP BY i.item_id,i.item_name,i.minimum_stock,u.unit_name HAVING current_stock<=i.minimum_stock ORDER BY current_stock ASC,i.item_name ASC";
$stmt=$conn->prepare($sql);
if($stmt){$stmt->bind_param('s',$branch_id);if($stmt->execute()){ $r=$stmt->get_result(); while($row=$r->fetch_assoc()){
    $qty=(int)$row['current_stock'];$min=(int)$row['minimum_stock'];$critical=$qty<=0;
    $type=$critical?'critical_stock':'low_stock';$title=$critical?'Critical Stock Alert':'Low Stock Alert';
    $message=sprintf('%s has %s %s remaining. Minimum stock is %s %s.',$row['item_name'],number_format($qty),$row['unit_name'],number_format($min),$row['unit_name']);
    saveNotification($conn,$user_id,$type,$title,$message,'condition:stock:'.$row['item_id']);
}}}$stmt->close();

/* EXPIRY: one alert per stock lot/date. */
$sql="SELECT s.stock_id,i.item_name,u.unit_name,s.quantity_available,s.expiration_date,DATEDIFF(s.expiration_date,CURDATE()) days_remaining FROM inventory_stocks s INNER JOIN inventory_items i ON i.item_id=s.item_id INNER JOIN units u ON u.unit_id=i.unit_id WHERE s.branch_id=? AND s.expiration_date IS NOT NULL AND s.quantity_available>0 AND s.expiration_date<=DATE_ADD(CURDATE(),INTERVAL 30 DAY) ORDER BY s.expiration_date,i.item_name";
$stmt=$conn->prepare($sql);
if($stmt){$stmt->bind_param('s',$branch_id);if($stmt->execute()){ $r=$stmt->get_result();while($row=$r->fetch_assoc()){
    $days=(int)$row['days_remaining'];$qty=(int)$row['quantity_available'];
    if($days<0){$title='Expired Stock Alert';$message=sprintf('%s expired on %s. %s %s remain in stock.',$row['item_name'],date('M j, Y',strtotime($row['expiration_date'])),number_format($qty),$row['unit_name']);}
    elseif($days===0){$title='Expiring Stock Alert';$message=sprintf('%s expires today. %s %s remain in stock.', $row['item_name'],number_format($qty),$row['unit_name']);}
    else{$title='Expiring Stock Alert';$message=sprintf('%s expires in %d day%s (%s). %s %s remain in stock.',$row['item_name'],$days,$days===1?'':'s',date('M j, Y',strtotime($row['expiration_date'])),number_format($qty),$row['unit_name']);}
    saveNotification($conn,$user_id,$days<0?'expired_stock':'expiring',$title,$message,'condition:expiry:'.$row['stock_id'].':'.$row['expiration_date']);
}}}$stmt->close();

/* PREDICTION: exact prediction_id prevents duplicate alerts. */
$sql="SELECT p.prediction_id,i.item_name,p.prediction_date,p.probability_score,p.prediction_status,p.recommended_reorder,p.predicted_consumption,p.forecast_days FROM prediction_results p INNER JOIN inventory_items i ON i.item_id=p.item_id WHERE p.branch_id=? AND (LOWER(COALESCE(p.prediction_status,'')) LIKE '%high%' OR LOWER(COALESCE(p.prediction_status,'')) LIKE '%shortage%' OR LOWER(COALESCE(p.prediction_status,'')) LIKE '%risk%') ORDER BY p.prediction_date DESC,p.prediction_id DESC";
$stmt=$conn->prepare($sql);
if($stmt){$stmt->bind_param('s',$branch_id);if($stmt->execute()){ $r=$stmt->get_result();while($row=$r->fetch_assoc()){
    $message='Shortage risk detected for '.$row['item_name'].'.'; if(trim((string)$row['prediction_status'])!=='')$message.=' Status: '.trim($row['prediction_status']).'.'; if($row['forecast_days']!==null)$message.=' Forecast: '.(int)$row['forecast_days'].' days.'; if($row['recommended_reorder']!==null)$message.=' Recommended reorder: '.number_format((int)$row['recommended_reorder']).'.';
    saveNotification($conn,$user_id,'prediction','Shortage Prediction Alert',$message,'prediction:'.$row['prediction_id']);
}}}$stmt->close();

/* STOCK MOVEMENTS: each transaction_id is a unique real inventory event. */
$sql="SELECT t.transaction_id,t.quantity,t.transaction_type,t.transaction_date,t.remarks,i.item_name,u.unit_name,COALESCE(actor.username,'Inventory User') actor_name FROM stock_transactions t INNER JOIN inventory_items i ON i.item_id=t.item_id INNER JOIN units u ON u.unit_id=i.unit_id LEFT JOIN users actor ON actor.user_id=t.user_id WHERE t.branch_id=? AND t.transaction_date>=DATE_SUB(NOW(),INTERVAL 7 DAY) AND t.transaction_type IN ('IN','OUT','ADJUSTMENT') ORDER BY t.transaction_date DESC,t.transaction_id DESC";
$stmt=$conn->prepare($sql);
if($stmt){$stmt->bind_param('s',$branch_id);if($stmt->execute()){ $r=$stmt->get_result();while($row=$r->fetch_assoc()){
    $type=(string)$row['transaction_type'];$qty=(int)$row['quantity'];$item=$row['item_name'];$unit=$row['unit_name'];$actor=$row['actor_name'];
    if($type==='IN'){$notifType='stock_in';$title='Stock In Confirmed';$message=sprintf('%s %s of %s were added to inventory by %s.',number_format($qty),$unit,$item,$actor);}
    elseif($type==='OUT'){$notifType='stock_out';$title='Stock Out Recorded';$message=sprintf('%s %s of %s were released from inventory by %s.',number_format($qty),$unit,$item,$actor);}
    else{$notifType='stock_adjustment';$title='Stock Adjustment Recorded';$message=sprintf('Stock for %s was adjusted by %s %s by %s.', $item,number_format($qty),$unit,$actor);}
    if(trim((string)$row['remarks'])!=='')$message.='';
    saveNotification($conn,$user_id,$notifType,$title,$message,'transaction:'.$row['transaction_id']);
}}}$stmt->close();

/* FILTERS + PAGINATION */
$search=trim((string)($_GET['search']??''));
$filter=(string)($_GET['filter']??'all');
$allowedFilters=['all','low_stock','critical_stock','expiring','expired_stock','prediction','stock_in','stock_out','stock_adjustment'];
if(!in_array($filter,$allowedFilters,true))$filter='all';
$page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT); if($page===false||$page<1)$page=1;
$perPage=10;
$where="n.user_id=? AND u.user_id=? AND u.branch_id=? AND u.status='Active'";
$params=[$user_id,$user_id,$branch_id];$types='iis';
if($filter!=='all'){$where.=' AND n.notification_type=?';$types.='s';$params[]=$filter;}
if($search!==''){$where.=" AND (n.title LIKE CONCAT('%',?,'%') OR n.message LIKE CONCAT('%',?,'%') OR n.notification_type LIKE CONCAT('%',?,'%'))";$types.='sss';array_push($params,$search,$search,$search);}
$countStmt=$conn->prepare("SELECT COUNT(*) total FROM notifications n INNER JOIN users u ON u.user_id=n.user_id WHERE $where");
$total=0;if($countStmt){bindDynamic($countStmt,$types,$params);if($countStmt->execute())$total=(int)($countStmt->get_result()->fetch_assoc()['total']??0);$countStmt->close();}
$totalPages=max(1,(int)ceil($total/$perPage));if($page>$totalPages)$page=$totalPages;$offset=($page-1)*$perPage;
$listSql="SELECT n.notification_id,n.title,n.message,n.notification_type,n.is_read,n.created_at FROM notifications n INNER JOIN users u ON u.user_id=n.user_id WHERE $where ORDER BY n.created_at DESC,n.notification_id DESC LIMIT ? OFFSET ?";
$listParams=$params;$listTypes=$types.'ii';$listParams[]=$perPage;$listParams[]=$offset;
$stmt=$conn->prepare($listSql);$notifications=[];
if($stmt){bindDynamic($stmt,$listTypes,$listParams);if($stmt->execute()){ $r=$stmt->get_result();while($row=$r->fetch_assoc()){$row['type']=notificationTypeClass((string)$row['notification_type']);$row['icon']=notifIcon((string)$row['notification_type']);$notifications[]=$row;}}$stmt->close();}
$countStmt=$conn->prepare("SELECT COUNT(*) unread_count FROM notifications n INNER JOIN users u ON u.user_id=n.user_id WHERE n.user_id=? AND u.user_id=? AND u.branch_id=? AND u.status='Active' AND n.is_read=0");$unreadCount=0;if($countStmt){$countStmt->bind_param('iis',$user_id,$user_id,$branch_id);if($countStmt->execute())$unreadCount=(int)($countStmt->get_result()->fetch_assoc()['unread_count']??0);$countStmt->close();}
$flashMessage=$_SESSION['notifications_flash_message']??'';$flashType=$_SESSION['notifications_flash_type']??'success';unset($_SESSION['notifications_flash_message'],$_SESSION['notifications_flash_type']);
?>


<!DOCTYPE html>
<html lang="en">
<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>Notifications</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">

<link rel="stylesheet"
href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">

<link rel="stylesheet" href="sidebar.css">

<style>

:root{
--primary:#2B3A8C;
--accent:#F21D2F;
--bg:#F2F2F2;
}

body{
background:#f0f2f5; 
font-family:'Segoe UI', Roboto, system-ui, sans-serif;
color:#1e293b;
}

.main{
margin-left:260px;
min-height:100vh;
}

.topbar{
background:white;
height:80px;
display:flex;
align-items:center;
justify-content:space-between;
padding:0 35px;
box-shadow:0 2px 12px rgba(0,0,0,0.04);
}

.topbar h3{
font-size:26px;
font-weight:700;
color:var(--primary);
margin:0;
}

.topbar h3 small{
    font-size:16px;
    font-weight:400;
    color:#777;
    margin-left:10px;
}

.top-unread-badge{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-width:22px;
    height:22px;
    padding:0 7px;
    margin-left:8px;
    border-radius:999px;
    background:#2B3A8C;
    color:#fff;
    font-size:11px;
    font-weight:700;
    vertical-align:middle;
}

.top-unread-badge[hidden]{
    display:none;
}

.profile{
font-weight:600;
color:var(--primary);
cursor:pointer;
display:flex;
align-items:center;
gap:4px;
}

.page-body{
padding:35px;
}
.toolbar{
display:flex;
align-items:center;
justify-content:space-between;
margin-bottom:28px;
flex-wrap:wrap;
gap:12px;
}

.toolbar-left{
display:flex;
align-items:center;
gap:14px;
flex-wrap:wrap;
}

.search-box{
display:flex;
align-items:center;
background:white;
border:1.5px solid #64748b;
border-radius:10px;
padding:0 16px;
height:42px;
width:320px;
transition:border 0.2s;
}

.search-box:focus-within{
border:1.5px solid var(--primary);
box-shadow:0 0 0 2px rgba(43,58,140,0.1);
}

.search-box i{
color:#94a3b8;
font-size:1.1rem;
margin-right:8px;
}

.search-box input{
border:none;
outline:none;
width:100%;
font-size:14px;
background:transparent;
color:#334155;
}

.search-box input::placeholder{
color:#94a3b8;
font-weight:500;
}

.btn-filter{
background:white;
color:var(--primary);
border:1.5px solid #64748b;
padding:0 18px;
height:42px;
border-radius:8px;
display:flex;
align-items:center;
gap:8px;
font-weight:600;
font-size:14px;
}

.btn-filter i{
font-size:0.9rem;
}

.branch-text{
color:#64748b;
font-size:14px;
font-weight:500;
}

.btn-mark-all{
background:#22c55e; /* Reference Green */
color:white;
border:none;
padding:0 22px;
height:42px;
border-radius:8px;
display:flex;
align-items:center;
gap:8px;
font-weight:600;
font-size:14px;
transition:background 0.2s;
}

.btn-mark-all:hover{
background:#16a34a;
}

/* --- DATE GROUP HEADER --- */
.date-header{
color:var(--primary);
font-size:18px;
font-weight:700;
margin:24px 0 16px 0;
}
.date-header:first-of-type{
margin-top:0;
}

/* --- FACEBOOK-STYLE NOTIFICATION CARD --- */
.notif-item{
    display:flex;
    background:#ffffff;
    border-radius:12px;
    margin-bottom:14px;
    padding:18px 20px;
    border:1px solid #e2e8f0;
    position:relative;
    box-shadow:0 1px 2px rgba(0,0,0,0.04);
    transition:background-color .18s ease, box-shadow .18s ease,
                border-color .18s ease, transform .18s ease;
    cursor:pointer;
    outline:none;
}

/* Unread = highlighted, like Facebook's unread notification treatment. */
.notif-item.is-unread{
    background:#eef3ff;
    border-color:#d5defa;
    box-shadow:0 1px 3px rgba(43,58,140,.08);
}

/* Read = quieter / less prominent. */
.notif-item.is-read{
    background:#ffffff;
    border-color:#e2e8f0;
    box-shadow:0 1px 2px rgba(0,0,0,.035);
}

.notif-item:hover,
.notif-item:focus-visible{
    background:#f8faff;
    box-shadow:0 5px 16px rgba(43,58,140,.10);
    border-color:#cbd5e1;
    transform:translateY(-1px);
}

.notif-item.is-unread:hover,
.notif-item.is-unread:focus-visible{
    background:#e5ecff;
    border-color:#b9c7f2;
}

.notif-item:active{
    transform:translateY(0);
}

/* Left accent borders based on type. */
.border-low_stock{ border-left:6px solid #ef4444; }
.border-expiring{ border-left:6px solid #eab308; }
.border-prediction{ border-left:6px solid #3b82f6; }
.border-stock_in{ border-left:6px solid #22c55e; }

.notif-icon-wrap{
    display:flex;
    align-items:flex-start;
    padding-right:16px;
}

.notif-icon{
    width:48px;
    height:48px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.4rem;
    flex-shrink:0;
}

.icon-low{ background:#fee2e2; color:#dc2626; }
.icon-expiring{ background:#fef9c3; color:#ca8a04; }
.icon-prediction{ background:#dbeafe; color:#2563eb; }
.icon-in{ background:#dcfce7; color:#16a34a; }

.notif-content{
    flex:1;
    min-width:0;
    padding:2px 0;
    display:flex;
    flex-direction:column;
    justify-content:center;
}

.notif-title{
    font-weight:700;
    color:#0f172a;
    font-size:15px;
    margin-bottom:4px;
}

.notif-message{
    font-size:14px;
    color:#475569;
    margin-bottom:6px;
    line-height:1.45;
}

.notif-time{
    font-size:12px;
    color:#94a3b8;
}

.notif-actions{
    display:flex;
    align-items:center;
    padding-left:16px;
    flex-shrink:0;
}

.badge-status-pill{
    padding:4px 14px;
    border-radius:30px;
    font-size:12px;
    font-weight:600;
    display:inline-block;
}

.badge-low_stock{ background:#fee2e2; color:#dc2626; }
.badge-expiring{ background:#fef9c3; color:#ca8a04; }
.badge-prediction{ background:#dbeafe; color:#2563eb; }
.badge-stock_in{ background:#dcfce7; color:#16a34a; }

.unread-dot{
    display:inline-block;
    width:8px;
    height:8px;
    border-radius:50%;
    background:#2B3A8C;
    margin-left:7px;
    vertical-align:middle;
}

/* Single details modal is opened by clicking the whole notification card. */
.notification-modal-icon{
    width:46px;
    height:46px;
    border-radius:12px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-size:1.25rem;
    flex-shrink:0;
}

.notification-modal-meta{
    display:grid;
    gap:8px;
    font-size:13px;
    color:#64748b;
}

.notification-modal-meta strong{
    color:#334155;
}

@media(max-width:991px){
    .main{ margin-left:90px; }
    .search-box{ width:100%; max-width:300px; }
    .toolbar-left{ width:100%; }
    .btn-mark-all{ width:100%; justify-content:center; }
}

@media(max-width:640px){
    .page-body{ padding:20px; }
    .notif-item{ padding:15px; }
    .notif-icon-wrap{ padding-right:12px; }
    .notif-icon{ width:42px; height:42px; font-size:1.2rem; }
    .notif-actions{ display:none; }
    .notif-message{ font-size:13px; }
}

.icon-out{background:#ffedd5;color:#ea580c}.icon-adjustment{background:#ede9fe;color:#7c3aed}
.border-stock_out{border-left:6px solid #f97316}.border-stock_adjustment{border-left:6px solid #8b5cf6}
.badge-stock_out{background:#ffedd5;color:#ea580c}.badge-stock_adjustment{background:#ede9fe;color:#7c3aed}
.notification-pagination{display:flex;justify-content:center;align-items:center;gap:6px;margin:28px 0 8px;flex-wrap:wrap}.page-link-custom{display:inline-flex;align-items:center;justify-content:center;min-width:38px;height:38px;padding:0 12px;border:1px solid #d7def0;border-radius:8px;background:#fff;color:#2B3A8C;text-decoration:none;font-size:13px;font-weight:600}.page-link-custom:hover{background:#eef3ff}.page-link-custom.active{background:#2B3A8C;color:#fff;border-color:#2B3A8C}.page-link-custom.disabled{opacity:.45;pointer-events:none}.pagination-summary{text-align:center;color:#94a3b8;font-size:12px;margin-bottom:20px}
</style>

</head>


<body>

<div class="sidebar">

<div class="logo-area">
    <div class="logo-frame">
        <img src="logo.png" alt="Smart Bite Care Logo" class="logo">
    </div>
    <div class="system-name">
        Smart Bite Care
    </div>
</div>

<nav class="nav-menu">
<ul>
<li><a href="InventoryOfficer_Dashboard.php"><i class="bi bi-grid-fill"></i><span>Dashboard</span></a></li>
<li><a href="InventoryOfficer_InventoryItems.php"><i class="bi bi-box-seam"></i><span>Inventory Items</span></a></li>
<li><a href="InventoryOfficer_Categories.php"><i class="bi bi-tags"></i><span>Categories & Units</span></a></li>
<li><a href="InventoryOfficer_StockManagement.php"><i class="bi bi-boxes"></i><span>Stock Management</span></a></li>
<li><a href="InventoryOfficer_StockTransactions.php"><i class="bi bi-arrow-left-right"></i><span>Stock Transactions</span></a></li>
<li><a href="InventoryOfficer_Reports.php"><i class="bi bi-file-earmark-bar-graph-fill"></i><span>Inventory Reports</span></a></li>
<li><a class="active" href="InventoryOfficer_Notifications.php"><i class="bi bi-bell-fill"></i><span>Notifications</span></a></li>
</ul>
</nav>

<div class="logout">
<a href="logout.php"> <i class="bi bi-box-arrow-right"></i>
<span>Logout</span>
</a>
</div>

</div>

<div class="main">

 <div class="topbar">
        <h3>Notifications
            <span id="topUnreadBadge" class="top-unread-badge" <?php echo $unreadCount > 0 ? '' : 'hidden'; ?>><?php echo $unreadCount; ?></span>
            <small><?php echo htmlspecialchars($branch_name); ?></small>
        </h3>
        <div class="profile">
            <i class="bi bi-person-circle"></i>
            <?php echo htmlspecialchars($username); ?>
            <span style="font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px;">| Inventory Officer</span>
        </div>
</div>


<div class="page-body">

<!-- Notification Toolbar -->
<div class="toolbar">
    <form method="get" class="toolbar-left" id="notificationFilters">
        <div class="search-box">
            <i class="bi bi-search"></i>
            <input type="text" name="search"
                   value="<?php echo h($search); ?>"
                   placeholder="Search Notifications..."
                   autocomplete="off" aria-label="Search notifications">
        </div>

        <select class="btn-filter" name="filter" aria-label="Filter notifications"
                onchange="this.form.submit()">
            <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>All Notifications</option>
            <option value="low_stock" <?php echo $filter === 'low_stock' ? 'selected' : ''; ?>>Low Stock</option>
            <option value="expiring" <?php echo $filter === 'expiring' ? 'selected' : ''; ?>>Expiring</option>
            <option value="prediction" <?php echo $filter === 'prediction' ? 'selected' : ''; ?>>Predicted Shortage</option>
            <option value="stock_in" <?php echo $filter === 'stock_in' ? 'selected' : ''; ?>>Stock In</option>
        </select>

        <span class="branch-text"><?php echo h($branch_name); ?></span>
    </form>

    <form method="post" style="margin:0;">
        <input type="hidden" name="csrf_token" value="<?php echo h($csrf_token); ?>">
        <input type="hidden" name="action" value="mark_all_read">
        <button type="submit" class="btn-mark-all" <?php echo $unreadCount === 0 ? 'disabled' : ''; ?>>
            <i class="bi bi-check-lg"></i> Mark All as Read
        </button>
    </form>
</div>

<?php if ($flashMessage !== ''): ?>
    <div class="alert alert-<?php echo $flashType === 'success' ? 'success' : 'danger'; ?>" role="alert">
        <?php echo h($flashMessage); ?>
    </div>
<?php endif; ?>

<?php if ($unreadCount > 0): ?>
    <div class="text-muted mb-3 unread-summary" style="font-size:13px;">
        <?php echo $unreadCount; ?> unread notification<?php echo $unreadCount === 1 ? '' : 's'; ?>
    </div>
<?php endif; ?>

<!-- Paginated Notifications -->
<?php if (empty($notifications)): ?>
    <div class="notif-item is-read" style="cursor:default;">
        <div class="notif-icon-wrap"><div class="notif-icon icon-prediction"><i class="bi bi-bell-slash"></i></div></div>
        <div class="notif-content">
            <div class="notif-title">No Notifications Found</div>
            <div class="notif-message">There are no notifications matching the current search or filter for <?php echo h($branch_name); ?>.</div>
        </div>
    </div>
<?php else: ?>
    <?php
    $lastGroup='';
    foreach($notifications as $n):
        $ts=strtotime((string)$n['created_at']);
        $date=$ts!==false?date('Y-m-d',$ts):'';
        $group=$date===date('Y-m-d')?'Today':($date===date('Y-m-d',strtotime('-1 day'))?'Yesterday':'Earlier');
        if($group!==$lastGroup): $lastGroup=$group;
    ?>
        <div class="date-header"><?php echo h($group); ?></div>
    <?php endif; $isRead=(int)$n['is_read']===1; $notificationId=(int)$n['notification_id']; ?>
        <div class="notif-item border-<?php echo h($n['type']); ?> <?php echo $isRead?'is-read':'is-unread'; ?>"
             id="notification-<?php echo $notificationId; ?>"
             data-notification-id="<?php echo $notificationId; ?>"
             data-is-read="<?php echo $isRead?'1':'0'; ?>"
             data-title="<?php echo h($n['title']?:'Notification'); ?>"
             data-message="<?php echo h($n['message']?:''); ?>"
             data-type="<?php echo h(badgeText($n['notification_type'])); ?>"
             data-created-at="<?php echo h(date('F j, Y g:i A',strtotime($n['created_at']))); ?>"
             data-icon="<?php echo h($n['icon']); ?>"
             data-icon-class="<?php echo h(notifIconClass($n['notification_type'])); ?>"
             data-branch="<?php echo h($branch_name); ?>"
             role="button" tabindex="0">
            <div class="notif-icon-wrap"><div class="notif-icon <?php echo h(notifIconClass($n['notification_type'])); ?>"><i class="bi <?php echo h($n['icon']); ?>"></i></div></div>
            <div class="notif-content">
                <div class="notif-title"><?php echo h($n['title']?:'Notification'); ?><span class="unread-dot" <?php echo $isRead?'hidden':''; ?>></span></div>
                <div class="notif-message"><?php echo h($n['message']?:''); ?></div>
                <div class="notif-time"><?php echo h(formatNotificationTime($n['created_at'])); ?></div>
            </div>
            <div class="notif-actions" aria-hidden="true"><span class="badge-status-pill badge-<?php echo h($n['type']); ?>"><?php echo h(badgeText($n['notification_type'])); ?></span></div>
        </div>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
    <nav class="notification-pagination" aria-label="Notification pages">
        <?php
        $buildPageUrl=function(int $p) use ($search,$filter){$q=['page'=>$p];if($search!=='')$q['search']=$search;if($filter!=='all')$q['filter']=$filter;return basename($_SERVER['PHP_SELF']).'?'.http_build_query($q);};
        ?>
        <a class="page-link-custom <?php echo $page<=1?'disabled':''; ?>" href="<?php echo $page>1?h($buildPageUrl($page-1)):'#'; ?>" aria-label="Previous">&laquo; Previous</a>
        <?php for($p=1;$p<=$totalPages;$p++): ?>
            <a class="page-link-custom <?php echo $p===$page?'active':''; ?>" href="<?php echo h($buildPageUrl($p)); ?>" aria-current="<?php echo $p===$page?'page':'false'; ?>"><?php echo $p; ?></a>
        <?php endfor; ?>
        <a class="page-link-custom <?php echo $page>=$totalPages?'disabled':''; ?>" href="<?php echo $page<$totalPages?h($buildPageUrl($page+1)):'#'; ?>" aria-label="Next">Next &raquo;</a>
    </nav>
    <div class="pagination-summary">Showing <?php echo $offset+1; ?>–<?php echo min($offset+$perPage,$total); ?> of <?php echo $total; ?> notifications</div>
    <?php endif; ?>
<?php endif; ?>

<!-- Existing details mechanism, now opened by clicking the entire card. -->
<div class="modal fade" id="notificationDetailsModal" tabindex="-1"
     aria-labelledby="notificationDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border:0;border-radius:14px;overflow:hidden;">
            <div class="modal-header">
                <div class="d-flex align-items-center gap-3">
                    <div id="notificationModalIcon" class="notification-modal-icon icon-prediction">
                        <i id="notificationModalIconGlyph" class="bi bi-bell-fill"></i>
                    </div>
                    <div>
                        <h5 class="modal-title mb-0" id="notificationDetailsModalLabel">Notification</h5>
                        <div id="notificationModalStatus" class="small text-muted mt-1">Read</div>
                    </div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p id="notificationModalMessage" class="mb-4" style="line-height:1.55;"></p>
                <div class="notification-modal-meta">
                    <div><strong>Type:</strong> <span id="notificationModalType"></span></div>
                    <div><strong>Date:</strong> <span id="notificationModalDate"></span></div>
                    <div><strong>Status:</strong> <span id="notificationModalReadState"></span></div>
                    <div><strong>Branch:</strong> <span id="notificationModalBranch"></span></div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>
</div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function(){'use strict';
const csrfToken=<?php echo json_encode($csrf_token); ?>;
const pageUrl=<?php echo json_encode(basename($_SERVER['PHP_SELF'])); ?>;
const modalEl=document.getElementById('notificationDetailsModal');
const modal=modalEl?new bootstrap.Modal(modalEl):null;
const topBadge=document.getElementById('topUnreadBadge');
const unreadText=document.querySelector('.unread-summary');
function updateUnreadCount(count){count=Math.max(0,parseInt(count,10)||0);if(topBadge){topBadge.textContent=count;topBadge.hidden=count===0;}if(unreadText){unreadText.textContent=count+' unread notification'+(count===1?'':'s');unreadText.style.display=count?'':'none';}const btn=document.querySelector('.btn-mark-all');if(btn)btn.disabled=count===0;}
function setRead(card){card.classList.remove('is-unread');card.classList.add('is-read');card.dataset.isRead='1';card.setAttribute('aria-label','Read: '+(card.dataset.title||'Notification'));const dot=card.querySelector('.unread-dot');if(dot)dot.hidden=true;}
function fillModal(card){if(!modalEl)return;document.getElementById('notificationModalIcon').className='notification-modal-icon '+(card.dataset.iconClass||'icon-prediction');document.getElementById('notificationModalIconGlyph').className='bi '+(card.dataset.icon||'bi-bell-fill');document.getElementById('notificationDetailsModalLabel').textContent=card.dataset.title||'Notification';document.getElementById('notificationModalMessage').textContent=card.dataset.message||'';document.getElementById('notificationModalType').textContent=card.dataset.type||'Update';document.getElementById('notificationModalDate').textContent=card.dataset.createdAt||'';document.getElementById('notificationModalBranch').textContent=card.dataset.branch||'';const read=card.dataset.isRead==='1';document.getElementById('notificationModalStatus').textContent=read?'Read':'Unread';document.getElementById('notificationModalReadState').textContent=read?'Read':'Unread';}
async function markRead(card){if(card.dataset.isRead==='1')return true;const id=Number(card.dataset.notificationId);if(!Number.isInteger(id)||id<=0){console.error('Invalid notification ID');return false;}const body=new URLSearchParams({csrf_token:csrfToken,action:'mark_read',notification_id:String(id)});try{const response=await fetch(pageUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Unable to mark notification as read.');setRead(card);updateUnreadCount(data.unread_count);return true;}catch(e){console.error(e);return false;}}
async function openCard(card){if(card.dataset.busy==='1')return;card.dataset.busy='1';try{if(!(await markRead(card)))return;fillModal(card);if(modal)modal.show();}finally{card.dataset.busy='0';}}
document.addEventListener('click',e=>{const card=e.target.closest('.notif-item[data-notification-id]');if(card)openCard(card);});
document.addEventListener('keydown',e=>{if(e.key!=='Enter'&&e.key!==' ')return;const card=e.target.closest('.notif-item[data-notification-id]');if(!card)return;e.preventDefault();openCard(card);});
const markAll=document.querySelector('.btn-mark-all');if(markAll){markAll.closest('form').addEventListener('submit',async e=>{e.preventDefault();if(markAll.disabled)return;const body=new URLSearchParams({csrf_token:csrfToken,action:'mark_all_read'});try{const response=await fetch(pageUrl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body});const data=await response.json();if(!response.ok||!data.success)throw new Error(data.message||'Unable to mark all as read.');document.querySelectorAll('.notif-item[data-notification-id]').forEach(setRead);updateUnreadCount(data.unread_count);}catch(e){console.error(e);window.alert(e.message||'Unable to mark notifications as read.');}});}
})();
</script>

</body>
</html>