<?php
declare(strict_types=1);
session_start();
require_once 'sources/db_connect.php';
require_once 'sources/forecast_accuracy_helper.php';
require_once 'sources/notification_helper.php';
require_once 'sources/forecast_reliability_view.php';
require_once 'sources/forecast_metrics_summary.php';
require_once 'sources/forecast_accuracy_units.php';
require_once 'sources/forecast_auto_evaluation.php';

// Provisional dashboard review targets, not universal accuracy standards.
// Changing these changes presentation only, never saved scores or forecasts.
const ACCURACY_REVIEW_HIGH_MAX = 10.0;
const ACCURACY_REVIEW_MODERATE_MAX = 20.0;
const ACCURACY_REVIEW_MIN_TEST_RECORDS = 7;
const ACCURACY_REVIEW_VERSION = 'sbc-review-bands-v1';

if (!isset($_SESSION['user_id'], $_SESSION['role_id']) || !in_array((int)$_SESSION['role_id'], [1,2], true)) {
    header('Location: login.php'); exit;
}
$userId=(int)$_SESSION['user_id'];$role=(int)$_SESSION['role_id'];
$stmt=$conn->prepare("SELECT user_id,role_id,branch_id,username FROM users WHERE user_id=? AND status='Active' AND role_id=? LIMIT 1");
$stmt->bind_param('ii',$userId,$role);$stmt->execute();$actor=$stmt->get_result()->fetch_assoc();$stmt->close();
if(!$actor){http_response_code(403);exit('Your active administrator assignment is required.');}
$notificationCount=getUnreadNotificationCount($conn,$userId);

function accuracyH($value):string{return htmlspecialchars((string)$value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function accuracyScore($value,int $places=4):string{
    if($value===null)return '—';
    if((float)$value!==0.0&&abs((float)$value)<0.0001)$places=8;
    return rtrim(rtrim(number_format((float)$value,$places,'.',','),'0'),'.');
}
function accuracyPeriod($start,$end):string{
    if($start===null)return 'No test window';
    return date('M j, Y',strtotime($start)).' – '.date('M j, Y',strtotime($end??$start));
}
function accuracyQuery(mysqli $conn,string $sql,string $types,array $params):mysqli_stmt{
    $stmt=$conn->prepare($sql);$stmt->bind_param($types,...$params);$stmt->execute();return $stmt;
}
function accuracyRating(array $row):array{
    if($row['evaluation_status']!=='Available'||$row['actual_total']===null||(float)$row['actual_total']<0
        ||$row['smape_percent']===null||!is_numeric($row['smape_percent'])
        ||(float)$row['smape_percent']<0||(float)$row['smape_percent']>200){
        return ['key'=>'unrated','label'=>'Not rated','note'=>'A complete test is not available.'];
    }
    if((int)$row['training_records']<1||(int)$row['testing_records']<ACCURACY_REVIEW_MIN_TEST_RECORDS){
        return ['key'=>'unrated','label'=>'Limited test data','note'=>'At least '.ACCURACY_REVIEW_MIN_TEST_RECORDS.' test observations and some item training records are required for a review band.'];
    }
    if($row['actual_total']!==null&&(float)$row['actual_total']===0.0){
        return ['key'=>'unrated','label'=>'No observed demand','note'=>'Zero actual consumption cannot demonstrate performance on changing demand. Check any forecast quantity and daily errors.'];
    }
    $smape=(float)$row['smape_percent'];
    if($smape<=ACCURACY_REVIEW_HIGH_MAX)return ['key'=>'high','label'=>'High','note'=>'Meets the provisional low-error target on this historical test.'];
    if($smape<=ACCURACY_REVIEW_MODERATE_MAX)return ['key'=>'moderate','label'=>'Moderate','note'=>'Within the provisional middle error band; compare the error in actual units.'];
    return ['key'=>'low','label'=>'Low','note'=>'Above the provisional error target; review this item before relying on its forecast.'];
}
function accuracyIcon(string $name):string{
    $paths=[
        'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'chart'=>'<path d="M4 4v16h17M7 15l4-5 4 3 5-7"/>',
        'check'=>'<path d="m7 12 3 3 7-7"/><circle cx="12" cy="12" r="9"/>',
        'target'=>'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'alert'=>'<path d="M12 3 2 20h20L12 3ZM12 9v4m0 3v.1"/>',
        'clock'=>'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'book'=>'<path d="M12 5v15M3 4c4-1 7-1 9 1 2-2 5-2 9-1v15c-4-1-7-1-9 1-2-2-5-2-9-1V4Z"/>',
        'download'=>'<path d="M12 3v12m-5-5 5 5 5-5M4 16v4h16v-4"/>',
        'search'=>'<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
        'arrow'=>'<path d="m14 5-7 7 7 7M7 12h14"/>',
        'chevron'=>'<path d="m9 5 7 7-7 7"/>',
        'menu'=>'<path d="M4 6h16M4 12h16M4 18h16"/>',
        'file'=>'<path d="M14 3H5v18h14V8l-5-5ZM14 3v5h5M8 12h8m-8 4h8"/>',
        'close'=>'<path d="m6 6 12 12M18 6 6 18"/>',
        'users'=>'<circle cx="9" cy="8" r="3"/><path d="M3 21v-3a6 6 0 0 1 12 0v3M16 5a3 3 0 0 1 0 6m2 3a5 5 0 0 1 3 4v3"/>',
        'heart'=>'<path d="M20 5a5 5 0 0 0-8 1 5 5 0 0 0-8-1c-5 5 8 15 8 15S25 10 20 5Z"/>',
        'stock'=>'<path d="m12 3 9 5v9l-9 5-9-5V8l9-5ZM3 8l9 5 9-5M12 13v9M7 5l10 6"/>',
        'bell'=>'<path d="M6 10a6 6 0 0 1 12 0v5l3 3H3l3-3v-5ZM10 21h4"/>',
        'settings'=>'<path d="m9 3-1 3-3 1-1 3 2 2-2 2 1 3 3 1 1 3h6l1-3 3-1 1-3-2-2 2-2-1-3-3-1-1-3H9Z"/><circle cx="12" cy="12" r="3"/>',
        'user'=>'<circle cx="12" cy="12" r="9"/><circle cx="12" cy="9" r="3"/><path d="M6 19a6 6 0 0 1 12 0"/>',
        'health'=>'<path d="M4 3h16v18H4V3ZM9 8h6m-3-3v6M8 15h8m-8 3h6"/>',

    ];
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'.($paths[$name]??$paths['chart']).'</svg>';
}

$rollingView=(string)($_GET['view']??'holdout')==='rolling';
$branches=$conn->query('SELECT branch_id,branch_name FROM branches ORDER BY branch_name,branch_id')->fetch_all(MYSQLI_ASSOC);
$branchMap=[];foreach($branches as $b)$branchMap[$b['branch_id']]=$b['branch_name'];
$requested=(string)($_GET['branch']??'');
if($role===2){
    $branchId=(string)($actor['branch_id']??'');
    if($branchId===''||($requested!==''&&$requested!==$branchId)){http_response_code(403);exit('This report is restricted to your assigned branch.');}
}else{$branchId=$requested!==''?$requested:(isset($branchMap[$actor['branch_id']??''])?$actor['branch_id']:($branches[0]['branch_id']??''));}
if($branchId!==''&&!isset($branchMap[$branchId])){http_response_code(404);exit('Branch was not found.');}
$branchName=$branchMap[$branchId]??'No branches';
// Small JSON status used by the page while a background evaluation runs.
if(isset($_GET['auto_status'])){
    header('Content-Type: application/json');header('Cache-Control: no-store');
    $state=forecastAutoEvaluationState($conn,$branchId);
    echo json_encode(['running'=>$state['running'],'revision'=>$state['revision'],
        'last_saved_job'=>$state['last_saved']?(int)$state['last_saved']['job_id']:null]);
    exit;
}
// Opening the report starts the automatic re-evaluation when the saved results
// are older than the branch's training data. It runs in the background; the
// page never waits for it and shows the previous results meanwhile.
$autoTrigger='unavailable';
if(!isset($_GET['export'])&&$branchId!==''){
    try{$autoTrigger=forecastAutoEvaluationTrigger($conn,$branchId,'Forecast Accuracy opened');}
    catch(Throwable $e){error_log('Automatic forecast evaluation trigger: '.$e->getMessage());}
}
$autoState=$branchId!==''?forecastAutoEvaluationState($conn,$branchId):['ready'=>false];
$method=(string)($_GET['method']??'all');if(!in_array($method,['all','one_step','recursive'],true))$method='all';
$horizon=(string)($_GET['horizon']??'all');if(!in_array($horizon,['all','1','7','14','30'],true))$horizon='all';
if($rollingView&&$horizon==='1')$horizon='all';
$search=trim((string)($_GET['q']??''));if(strlen($search)>200){http_response_code(400);exit('Use a shorter item search.');}
$perPage=filter_var($_GET['per_page']??10,FILTER_VALIDATE_INT);if(!in_array($perPage,[10,20,50],true))$perPage=10;
$page=filter_var($_GET['page']??1,FILTER_VALIDATE_INT);if($page===false||$page<1)$page=1;
// Errors in vials/ampules by default; "base" shows the saved numbers unchanged.
$unitMode=forecastAccuracyUnitMode();$unitMap=forecastAccuracyUnitMap($conn);
$runId=null;
if(!$rollingView&&isset($_GET['run'])){
    $value=(string)$_GET['run'];if(!preg_match('/^[1-9][0-9]*$/D',$value)||strlen($value)>18){http_response_code(400);exit('Invalid evaluation selection.');}$runId=(int)$value;
}
$rolling=$rollingView?forecastReliabilityLoad($conn,$branchId,$search,$horizon,$perPage,$page):null;
if($rollingView&&isset($_GET['export'])){http_response_code(409);exit('No saved rolling evaluation is available for export.');}
$problems=$rollingView?[]:forecastAccuracySchemaProblems($conn);$runs=[];$run=null;$rows=[];
$overallMetrics=['overall'=>[],'by_unit'=>[]];
$totals=['total'=>0,'high'=>0,'moderate'=>0,'low'=>0,'unrated'=>0];$totalPages=1;
$where='';$queryTypes='';$queryParams=[];
if(!$rollingView&&!$problems&&$branchId!==''){
    $stmt=accuracyQuery($conn,'SELECT * FROM forecast_evaluation_runs WHERE branch_id=? ORDER BY evaluation_id DESC LIMIT 200','s',[$branchId]);$runs=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    if($runId===null&&$runs)$runId=(int)$runs[0]['evaluation_id'];
    if($runId!==null){
        $stmt=accuracyQuery($conn,'SELECT * FROM forecast_evaluation_runs WHERE evaluation_id=? AND branch_id=?','is',[$runId,$branchId]);$run=$stmt->get_result()->fetch_assoc();$stmt->close();
        if(!$run){http_response_code(404);exit('Evaluation was not found in this branch.');}
        $parts=['evaluation_id=?'];$queryTypes='i';$queryParams=[$runId];
        if($method!=='all'){$parts[]='method=?';$queryTypes.='s';$queryParams[]=$method;}
        if($horizon!=='all'){$parts[]='horizon_days=?';$queryTypes.='i';$queryParams[]=(int)$horizon;}
        if($search!==''){$parts[]="item_name LIKE ? ESCAPE '!'";$queryTypes.='s';$queryParams[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';}
        $where=' FROM forecast_evaluation_items WHERE '.implode(' AND ',$parts);
        // Aggregate the complete filtered result set, independently of pagination.
        $stmt=accuracyQuery($conn,'SELECT item_id,base_unit,method,horizon_days,evaluation_status,training_records,testing_records,mae,rmse,smape_percent'.$where,$queryTypes,$queryParams);
        $overallMetrics=forecastMetricsSummarize($stmt->get_result(),false,$unitMap,$unitMode);$stmt->close();
        $high=sprintf('%.4f',ACCURACY_REVIEW_HIGH_MAX);$moderate=sprintf('%.4f',ACCURACY_REVIEW_MODERATE_MAX);$minimum=ACCURACY_REVIEW_MIN_TEST_RECORDS;
        $rated="evaluation_status='Available' AND training_records>0 AND testing_records>=$minimum AND actual_total>0 AND smape_percent BETWEEN 0 AND 200";
        $stmt=accuracyQuery($conn,"SELECT COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN $rated AND smape_percent<=$high THEN 1 ELSE 0 END),0) AS high,
            COALESCE(SUM(CASE WHEN $rated AND smape_percent>$high AND smape_percent<=$moderate THEN 1 ELSE 0 END),0) AS moderate,
            COALESCE(SUM(CASE WHEN $rated AND smape_percent>$moderate THEN 1 ELSE 0 END),0) AS low".$where,$queryTypes,$queryParams);
        $totals=array_map('intval',$stmt->get_result()->fetch_assoc());$stmt->close();
        $totals['unrated']=$totals['total']-$totals['high']-$totals['moderate']-$totals['low'];
        $totalPages=max(1,(int)ceil($totals['total']/$perPage));$page=min($page,$totalPages);$offset=($page-1)*$perPage;
        if(!isset($_GET['export'])){
            $stmt=accuracyQuery($conn,'SELECT *'.$where.' ORDER BY item_name,item_id,horizon_days,method,evaluation_item_id LIMIT ? OFFSET ?',
                $queryTypes.'ii',[...$queryParams,$perPage,$offset]);$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
            $rows=array_map(static fn(array $r):array=>forecastAccuracyConvertRow($r,$unitMap,$unitMode,false),$rows);
        }
        if(!array_filter($runs,static fn(array $r):bool=>(int)$r['evaluation_id']===$runId))array_unshift($runs,$run);
    }
}
if(!$rollingView&&isset($_GET['export'])){
    if(!$run||$problems){http_response_code(409);exit('No saved evaluation is available for export.');}
    // Export every filtered row, independently of the visible page.
    $stmt=accuracyQuery($conn,'SELECT *'.$where.' ORDER BY item_name,item_id,horizon_days,method,evaluation_item_id',$queryTypes,$queryParams);
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="forecast-accuracy-'.(int)$runId.'.csv"');header('Cache-Control: no-store');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Evaluation ID','Branch','Saved at (Manila)','Generated forecast horizon','Evaluation version','XGBoost version','Source hash','Item ID','Item','Base unit','Method','Test horizon days','Status','Training records','Testing records','Training start','Training end','Testing start','Testing end','MAE','RMSE','SMAPE percent','Actual total','Forecast total','Total absolute error','Reason','Provisional review band','Review explanation','Review policy','High band max SMAPE','Moderate band max SMAPE','Minimum test records for band']);
    $result=$stmt->get_result();
    while($r=$result->fetch_assoc()){
        $rating=accuracyRating($r);
        $values=[$runId,$branchId,$run['generated_at'],$run['generated_horizon'],$run['evaluation_version'],$run['model_version'],$run['source_hash'],$r['item_id'],$r['item_name'],$r['base_unit'],$r['method'],$r['horizon_days'],$r['evaluation_status'],$r['training_records'],$r['testing_records'],$r['training_start'],$r['training_end'],$r['testing_start'],$r['testing_end'],$r['mae'],$r['rmse'],$r['smape_percent'],$r['actual_total'],$r['forecast_total'],$r['total_absolute_error'],$r['reason'],$rating['label'],$rating['note'],ACCURACY_REVIEW_VERSION,ACCURACY_REVIEW_HIGH_MAX,ACCURACY_REVIEW_MODERATE_MAX,ACCURACY_REVIEW_MIN_TEST_RECORDS];
        foreach($values as &$cell)if(is_string($cell)&&preg_match('/^[\s]*[=+@-]/u',$cell))$cell="'".$cell;unset($cell);fputcsv($out,$values);
    }
    $stmt->close();fclose($out);exit;
}
$urlParams=['branch'=>$branchId,'method'=>$method,'horizon'=>$horizon,'q'=>$search,'per_page'=>$perPage];if($runId!==null)$urlParams['run']=$runId;
if($rollingView)$urlParams=['view'=>'rolling','branch'=>$branchId];
if($unitMode==='base')$urlParams['units']='base';
function accuracyUrl(array $changes=[]):string{global $urlParams;return 'ForecastAccuracy.php?'.http_build_query(array_replace($urlParams,$changes));}
$dashboard=$role===1?'SuperAdmin_Dashboard.php':'BranchAdmin_Dashboard.php';$back=$role===1?$dashboard:'BranchAdmin_Forecasting.php';
$reports=$role===1?'SuperAdmin_Reports.php':'BranchAdmin_Reports.php';$first=$totals['total']?($page-1)*$perPage+1:0;$last=min($page*$perPage,$totals['total']);
$pageNumbers=array_unique([1,...range(max(1,$page-2),min($totalPages,$page+2)),$totalPages]);sort($pageNumbers);
$navigation=$role===2 ? [
    ['BranchAdmin_Dashboard.php','Dashboard','grid'],
    ['BranchAdmin_UserManagement.php','User Management','users'],
    ['BranchAdmin_PatientMonitoring.php','Patient Monitoring','heart'],
    ['BranchAdmin_PhilhealthWorkflow.php','PhilHealth Processing','health'],
    ['BranchAdmin_InventoryOverview.php','Inventory Overview','stock'],
    ['BranchAdmin_Forecasting.php','Supply Forecasting','chart'],
    ['BranchAdmin_Reports.php','Reports','file'],
    ['BranchAdmin_AuditLogs.php','Audit Logs','clock'],
    ['BranchAdmin_Notifications.php','Notifications','bell'],
    ['BranchAdmin_Settings.php','Settings','settings'],
] : [
    ['SuperAdmin_Dashboard.php','Dashboard','grid'],
    ['SuperAdmin_CombinedManagement.php','Branch Management','users'],
    ['SuperAdmin_UserMonitoring.php','User Monitoring','user'],
    ['SuperAdmin_BranchPerformanceMonitoring.php','Branch Performance','chart'],
    ['ForecastAccuracy.php','Forecast Accuracy','target'],
    ['SuperAdmin_Reports.php','Reports','file'],
    ['SuperAdmin_AuditLogs.php','Audit Logs','clock'],
    ['SuperAdmin_Notifications.php','Notifications','bell'],
];
$chronologicalEvidence=$run && $run['training_end']!==null && $run['testing_start']!==null
    && $run['training_end']<$run['testing_start'];

?>
<!DOCTYPE html>
<html lang="en">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Forecast Accuracy — SmartBiteCare</title>
<style>
:root{--nav:#15263f;--nav-muted:#aab6ca;--primary:#304baa;--ink:#172944;--muted:#728097;--border:#e6ebf3;--bg:#f5f7fb;--green:#168065;--amber:#a06a0b;--red:#c44251;--radius:16px}
*{box-sizing:border-box}body{margin:0;font-family:'Segoe UI',Arial,sans-serif;background:var(--bg);color:var(--ink);font-size:14px}a{color:inherit;text-decoration:none}button,input,select{font:inherit}button,a,input,select,summary{outline-offset:4px}button{cursor:pointer}svg{flex-shrink:0}button:disabled{cursor:default}button,a{touch-action:manipulation}
.sidebar{width:232px;background:var(--nav);color:#fff;position:fixed;inset:0 auto 0 0;padding:28px 18px;display:flex;flex-direction:column;z-index:40}.brand{display:flex;align-items:center;gap:11px;padding:0 9px 28px;border-bottom:1px solid #ffffff16}.brand img{width:43px;height:43px;object-fit:contain;border-radius:12px;background:#fff}.brand strong{font-size:16px;display:block;letter-spacing:-.4px}.brand small{color:var(--nav-muted);font-size:11px}.nav-label{font-size:10px;text-transform:uppercase;letter-spacing:1.7px;color:#7185a5;margin:27px 14px 12px}.nav-link{display:flex;align-items:center;gap:12px;color:#b8c4d8;padding:13px 15px;border-radius:10px;margin-bottom:6px;font-weight:550}.nav-link:hover{background:#ffffff09;color:#fff}.nav-link.active{background:#304ba938;color:#fff;box-shadow:inset 3px 0 #8199f2}.nav-link.active svg{color:#b6c6ff}.side-footer{margin-top:auto;border-top:1px solid #ffffff16;padding:20px 9px 0;display:flex;align-items:center;gap:10px}.avatar{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;background:#ffffff12;font-weight:650}.side-footer strong{font-size:12px;display:block;max-width:145px;overflow:hidden;text-overflow:ellipsis}.side-footer small{color:var(--nav-muted);font-size:11px}
.main{margin-left:232px}.topbar{height:76px;padding:0 34px;display:flex;align-items:center;justify-content:space-between;background:#fff;border-bottom:1px solid var(--border);gap:12px}.breadcrumb{font-size:12px;color:var(--muted);display:flex;align-items:center;gap:10px}.breadcrumb strong{color:var(--ink);font-weight:550}.branch-pill{display:flex;align-items:center;gap:8px;font-size:12px;color:#596b86;background:#f5f7fb;border:1px solid var(--border);padding:8px 12px;border-radius:30px;max-width:50%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.dot{width:6px;height:6px;background:#35a583;border-radius:50%;flex-shrink:0}.menu-button{display:none}.content{max-width:1560px;padding:32px 34px 36px;margin:auto}.hero{display:flex;justify-content:space-between;align-items:center;gap:18px;margin-bottom:25px}.eyebrow{font-size:10px;letter-spacing:1.8px;text-transform:uppercase;font-weight:650;color:var(--primary);margin-bottom:8px}h1{font-size:29px;letter-spacing:-1px;margin:0 0 8px;line-height:1.25;font-weight:720}.hero p{color:var(--muted);margin:0;font-size:13px;line-height:1.6}.actions{display:flex;gap:9px;align-items:center;flex-shrink:0}.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:1px solid var(--border);border-radius:9px;padding:10px 14px;background:#fff;font-size:12px;font-weight:600;min-height:40px;white-space:nowrap}.btn:hover{border-color:#b5c4e2;background:#f8faff}.btn-primary{background:var(--primary);color:#fff;border-color:var(--primary)}.btn-primary:hover{background:#253f98;border-color:#253f98}.icon-btn{border:0;background:transparent;padding:7px;color:var(--muted);display:grid;place-items:center;border-radius:7px}.icon-btn:hover{background:#edf1fa;color:var(--primary)}
.panel{background:#fff;border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 3px 9px #17294403;overflow:hidden;margin-bottom:20px}.filters{padding:19px 22px;display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap}.field{flex:1;min-width:130px}.field-wide{flex:2;min-width:235px}.field-small{flex:0 0 118px}.field label{font-size:10px;color:#7c8aa1;font-weight:650;letter-spacing:1px;text-transform:uppercase;display:block;margin-bottom:8px}.field input,.field select{border:1px solid #dfe5ef;background:#fff;border-radius:8px;min-height:40px;width:100%;color:var(--ink);padding:9px 12px;font-size:12px}.field select{padding-right:25px}.search-box{position:relative}.search-box svg{position:absolute;left:11px;top:11px;color:#9ba8bb;width:17px;height:17px}.search-box input{padding-left:37px}.filter-clear{font-size:12px;color:var(--muted);margin-bottom:12px;white-space:nowrap}.filter-clear:hover{color:var(--primary)}
.cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin-bottom:20px}.metric-card{border:1px solid var(--border);background:#fff;border-radius:13px;padding:18px 20px;display:flex;gap:14px;align-items:flex-start}.metric-icon{width:39px;height:39px;border-radius:11px;display:grid;place-items:center;background:#ebf7f2;color:var(--green);flex-shrink:0}.metric-card.moderate .metric-icon{background:#fff5df;color:var(--amber)}.metric-card.low .metric-icon{background:#fff0f2;color:var(--red)}.metric-card.unrated .metric-icon{background:#f0f3f8;color:#7786a0}.metric-label{font-size:11px;color:var(--muted);font-weight:550}.metric-value{font-size:28px;font-weight:720;line-height:1.3;letter-spacing:-.5px;margin-top:2px}.metric-note{font-size:10px;color:#98a2b3;margin-top:2px}
.run-info{display:flex;gap:24px;flex-wrap:wrap;padding:17px 22px;background:#fafbfd;border-bottom:1px solid var(--border)}.run-info span{font-size:10px;color:var(--muted);display:block;margin-bottom:5px}.run-info strong{font-size:11px;font-weight:600}.run-info .run-id{display:flex;align-items:center;gap:10px;padding-right:22px;border-right:1px solid var(--border)}.run-id svg{color:#a4b1c8}.run-id span{margin:0}.run-id strong{font-size:13px}.table-header{padding:21px 22px 17px;display:flex;align-items:center;justify-content:space-between;gap:10px}.table-header h2{font-size:16px;letter-spacing:-.3px;margin:0 0 5px}.table-header p{font-size:11px;color:var(--muted);margin:0}.summary-count{font-size:11px;color:var(--muted);background:#f3f5f9;border-radius:6px;padding:6px 9px;white-space:nowrap}.table-scroll{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:12px;text-align:left}thead{background:#f7f9fc;border-top:1px solid var(--border);border-bottom:1px solid var(--border)}th{padding:12px 17px;font-size:10px;letter-spacing:.7px;text-transform:uppercase;color:#8590a3;font-weight:650;white-space:nowrap}th:first-child,td:first-child{padding-left:22px}td{padding:17px;border-bottom:1px solid #edf0f6;vertical-align:middle}.result-row:hover{background:#fbfcff}.item-name{font-weight:650;font-size:12px;display:block;max-width:230px;line-height:1.55;overflow-wrap:anywhere}.item-meta{font-size:10px;color:#95a0b2;margin-top:5px;display:flex;align-items:center;gap:7px}.unit-tag{background:#f1f4fa;color:#6b7f9f;padding:2px 6px;border-radius:4px;font-size:9px}.test-label{font-weight:550;white-space:nowrap}.test-count{font-size:10px;color:#98a2b4;margin-top:5px}.numeric{font-variant-numeric:tabular-nums;white-space:nowrap}.error-pair,.total-pair{display:grid;grid-template-columns:auto auto;gap:4px 12px;align-items:center;width:max-content}.error-pair span,.total-pair span{font-size:9px;color:#94a0b3}.error-pair strong,.total-pair strong{font-size:11px;font-weight:550}.smape-value{font-size:12px;font-weight:650}.smape-track{width:67px;height:3px;background:#edf0f6;border-radius:6px;margin-top:7px;overflow:hidden}.smape-track span{display:block;height:100%;background:var(--green);border-radius:6px}.smape-track.moderate span{background:#cc9840}.smape-track.low span{background:#dd7683}.smape-track.unrated span{background:#a4b1c8}.rating-cell{min-width:150px}.pill{display:inline-flex;align-items:center;gap:6px;border-radius:30px;padding:5px 9px;font-size:10px;font-weight:650;white-space:nowrap;background:#eaf6f1;color:var(--green)}.pill::before{content:'';width:5px;height:5px;background:currentColor;border-radius:50%;flex-shrink:0}.pill.moderate{background:#fff5df;color:var(--amber)}.pill.low{background:#fff0f2;color:var(--red)}.pill.unrated{background:#f0f3f8;color:#74839a}.row-detail-toggle{border:0;background:none;color:#8492ab;font-size:10px;display:block;padding:0;margin-top:8px}.row-detail-toggle:hover{color:var(--primary)}.row-detail-toggle[aria-expanded=true]{color:var(--primary)}.details-row{background:#f8faff}.details-row td{padding:18px 22px}.details-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.details-grid h3{font-size:10px;color:#8d9ab0;text-transform:uppercase;letter-spacing:.8px;margin:0 0 7px}.details-grid p{font-size:11px;line-height:1.7;margin:0;color:#526783}.reason-box{margin-top:12px;background:#fff5df;border:1px solid #f0dfaf;padding:11px 13px;border-radius:8px;color:#806325;font-size:11px;line-height:1.7}.reason-box b{display:block;margin-bottom:3px}.empty-cell{text-align:center;padding:45px 22px}.empty-cell svg{color:#a9b8d2;margin-bottom:12px;width:30px;height:30px}.empty-cell strong{display:block;color:#677b99;font-size:13px}.empty-cell p{font-size:11px;line-height:1.8;color:#95a1b6;margin:8px auto 0;max-width:440px}
.table-footer{padding:16px 22px;display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}.page-info{font-size:11px;color:#92a0b6}.pagination{display:flex;align-items:center;gap:4px}.page-link{display:grid;place-items:center;min-width:30px;height:30px;padding:0 8px;border:1px solid transparent;border-radius:6px;font-size:11px;color:#7b8da7}.page-link:hover{background:#f2f5fb}.page-link.current{background:var(--primary);color:white}.page-link.disabled{color:#ced5e0}.page-gap{color:#a2aec0;font-size:11px;padding:0 4px}.page-prev svg{transform:rotate(180deg);width:14px;height:14px}.page-next svg{width:14px;height:14px}.reading-strip{padding:16px 20px;border-radius:12px;background:#edf2fd;border:1px solid #dfe7fb;display:flex;align-items:center;gap:13px;margin-bottom:15px}.reading-strip>svg{color:#637ac0}.reading-strip p{font-size:11px;line-height:1.7;color:#6b7fab;margin:0;flex:1}.reading-strip strong{color:#3b5391;display:block;font-size:12px}.text-link{font-size:11px;font-weight:650;color:var(--primary);border:0;background:none;white-space:nowrap;padding:6px}.policy-note{font-size:10px;color:#96a2b6;line-height:1.8;margin:0}.policy-note button{font-size:10px;padding:0;white-space:normal;font-weight:550}.setup-alert{padding:16px 20px;color:#846a2e;background:#fff7e5;border:1px solid #f1e0b8;border-radius:12px;font-size:12px;line-height:1.8;margin-bottom:20px}.empty-state{padding:45px 25px;text-align:center}.empty-state>svg{width:43px;height:43px;color:#94a9d5;margin:10px auto 20px}.empty-state h2{font-size:18px}.empty-state p{max-width:560px;margin:8px auto 18px;font-size:12px;color:var(--muted);line-height:1.9}
dialog{border:0;padding:0;border-radius:20px;max-width:850px;width:calc(100% - 30px);max-height:88vh;color:var(--ink);box-shadow:0 25px 100px #17294440}dialog::backdrop{background:#12243f70;backdrop-filter:blur(3px)}.guide-head{position:sticky;top:0;z-index:1;padding:22px 26px;border-bottom:1px solid var(--border);background:#fff;display:flex;justify-content:space-between;align-items:flex-start}.guide-head h2{font-size:20px;margin:0 0 7px;letter-spacing:-.4px}.guide-head p{font-size:12px;color:var(--muted);margin:0}.guide-body{padding:24px 26px}.guide-body h3{font-size:14px;margin:24px 0 12px}.guide-body h3:first-child{margin-top:0}.guide-body p,.guide-body li{font-size:12px;line-height:1.9;color:#61738f}.guide-body ol{padding-left:20px}.guide-body li{padding-left:5px;margin-bottom:9px}.guide-bands{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:12px 0}.band{border:1px solid var(--border);padding:15px;border-radius:11px}.band strong{display:block;font-size:17px;margin:10px 0 5px}.band p{font-size:11px;margin:0}.guide-callout{background:#f3f6fc;border:1px solid var(--border);border-radius:10px;padding:13px 16px;font-size:12px;line-height:1.9;color:#607697}.definitions{display:grid;grid-template-columns:1fr 1fr;gap:12px}.definition{border:1px solid var(--border);border-radius:10px;padding:14px}.definition b{font-size:12px}.definition p{font-size:11px;margin:6px 0 0}.example{background:#eff8f4;border:1px solid #d5ebe1;border-radius:11px;padding:16px;margin-top:14px}.example p{margin:0;color:#416e5a;font-size:12px;line-height:1.9}.guide-body details{border-top:1px solid var(--border);margin-top:24px;padding-top:16px}.guide-body summary{font-weight:650;font-size:12px;cursor:pointer;color:#596e91}.guide-body a{color:var(--primary);text-decoration:underline}.overlay{display:none}
@media(min-width:1500px){.content{padding-top:36px}.filters{gap:16px}.metric-card{padding:20px 24px}td{padding-top:20px;padding-bottom:20px}}
@media(max-width:1180px){.sidebar{width:204px;padding-left:13px;padding-right:13px}.main{margin-left:204px}.topbar{padding:0 24px}.content{padding:26px 24px}.metric-card{padding:16px 14px;gap:10px}.metric-icon{width:32px;height:32px}.metric-value{font-size:25px}.hero{align-items:flex-start}.run-info{gap:18px}.field-wide{min-width:210px}}
@media(max-width:900px){.sidebar{transform:translateX(-100%);transition:transform .2s;width:232px}.main{margin-left:0}.menu-button{display:grid}.topbar{height:65px;padding:0 18px}.topbar-left{display:flex;gap:9px;align-items:center}.content{padding:24px 18px}.nav-open .sidebar{transform:none}.nav-open .overlay{display:block;position:fixed;inset:0;background:#10213965;z-index:35}.hero h1{font-size:25px}.actions{flex-direction:column;align-items:stretch}.cards{gap:10px}.metric-card{padding:14px 12px;flex-direction:column;gap:8px}.metric-note{font-size:9px}.metric-icon{width:30px;height:30px}.breadcrumb{font-size:11px}.branch-pill{font-size:10px}.details-grid{grid-template-columns:1fr 1fr}.details-grid>div:last-child{grid-column:1/-1}}
@media(max-width:570px){.hero{display:block;margin-bottom:19px}.hero p{font-size:12px}.actions{flex-direction:row;justify-content:flex-start;margin-top:17px}.actions .btn{font-size:11px;padding:9px 12px;min-height:37px}.cards{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.metric-card{flex-direction:row;padding:14px;gap:11px}.metric-value{font-size:24px}.metric-label{font-size:10px}.filters{padding:16px;gap:13px}.field{flex-basis:calc(50% - 7px);min-width:0}.field-wide{flex-basis:100%}.field-search{flex-basis:100%}.field-small{flex:1}.filters .btn{flex:1}.filter-clear{margin:0;padding:12px 0}.table-header{padding:18px 16px}.table-header h2{font-size:14px}.run-info{padding:16px;gap:14px}.run-info>div{flex:1 1 42%}.run-info .run-id{border-right:0;padding-right:0}.reading-strip{align-items:flex-start;padding:14px;gap:10px}.reading-strip p{font-size:10px}.reading-strip>.text-link{display:none}.table-footer{padding:14px 16px}.table-footer .pagination{margin-left:auto}.branch-pill{max-width:47%;padding:7px 9px}.breadcrumb .crumb-parent{display:none}.guide-head{padding:18px}.guide-head h2{font-size:18px}.guide-body{padding:19px}.guide-bands{grid-template-columns:1fr}.band{display:grid;grid-template-columns:85px auto;align-items:center;gap:8px}.band strong{font-size:14px;margin:0}.band p{grid-column:1/-1}.definitions{grid-template-columns:1fr}.details-grid{grid-template-columns:1fr}.details-grid>div:last-child{grid-column:auto}}
@media(prefers-reduced-motion:reduce){.sidebar{transition:none}}
.icon-btn.menu-button{display:none}.mobile-table-hint{display:none;margin:0;padding:0 16px 12px;font-size:10px;color:#8b9ab3}
@media(max-width:900px){.icon-btn.menu-button{display:grid}.sidebar{visibility:hidden}.nav-open .sidebar{visibility:visible}.mobile-table-hint{display:block}}
.rolling-results table{min-width:950px}
@media(max-width:700px){.rolling-table-header{align-items:stretch;flex-direction:column}.rolling-table-header .actions{flex-direction:row;flex-wrap:wrap;justify-content:flex-start}.rolling-table-header .actions .btn{flex:1;min-width:0;white-space:normal}}
</style>
<link rel="stylesheet" href="sidebar.css">
<style>
:root{--primary:#2b3a8c;--ink:#142339;--muted:#7080a0;--bg:#f9faff;--border:#e6ebf5;--green:#28a745;--amber:#cf9300;--red:#dc3545}
body{background:#f9faff;font-size:15px}.sidebar{padding:0;visibility:visible;background:#2b3a8c;z-index:2000}.sidebar-toggle i{display:flex;align-items:center;justify-content:center}.sidebar-toggle svg{transform:rotate(180deg);width:18px}.sidebar.collapsed .sidebar-toggle svg{transform:none}.nav-menu a>i{display:flex;align-items:center;justify-content:center}.nav-menu a>i svg{width:20px;height:20px}.nav-menu .accuracy-subnav{margin-top:-3px;margin-bottom:9px}.nav-menu .accuracy-subnav a{font-size:13px;padding:8px 14px 8px 29px;opacity:.9}.notification-badge{margin-left:auto;border-radius:20px;background:#f21d2f;color:#fff;padding:4px 10px;min-width:25px;font-size:10px;font-weight:700;text-align:center}.sidebar.collapsed .notification-badge{display:none}
.main{margin-left:260px}body.sidebar-collapsed .main{margin-left:90px}.topbar{height:80px;padding:0 35px;background:#fff;box-shadow:0 2px 8px #0000000f;border-bottom:1px solid #e9edf5}.topbar-left{display:flex;align-items:center;gap:10px;min-width:0}.topbar h3{font-size:28px;color:#2b3a8c;font-weight:700;margin:0;line-height:1.25}.topbar h3 small{font-size:16px;font-weight:400;color:#666;margin-left:10px}.account-menu{position:relative;flex-shrink:0}.profile{display:flex;gap:7px;align-items:center;color:#2b3a8c;font-weight:600;padding:10px 0 10px 14px;cursor:pointer;list-style:none;font-size:14px}.profile::-webkit-details-marker{display:none}.profile-role{font-size:12px;color:#adb5bd;font-weight:400;margin-left:4px}.profile-caret{margin-left:5px}.account-options{position:absolute;right:0;top:100%;min-width:185px;border:1px solid #e7ebf3;background:#fff;border-radius:9px;box-shadow:0 8px 25px #1828411a;padding:7px;z-index:2100}.account-options a,.account-options button{font-size:13px;border:0;background:#fff;display:block;width:100%;text-align:left;padding:11px;border-radius:6px}.account-options a:hover,.account-options button:hover{background:#f3f5fb}.account-options button{color:#dc3545}.content{max-width:none;margin:0;padding:35px 35px 40px}.hero{background:white;border:1px solid #e8ebf5;border-radius:16px;padding:20px;margin-bottom:24px;box-shadow:0 4px 12px #00000005}.hero h1{font-size:17px;color:#2b3a8c;font-weight:700;margin:0 0 7px;letter-spacing:0;display:flex;gap:8px;align-items:center}.hero p{font-size:13px;line-height:1.7}.btn{font-size:13px;border-radius:8px;border-color:#dbe2f0;padding:9px 13px}.btn-primary{background:#0d6efd;border-color:#0d6efd}.btn-primary:hover{background:#0b5ed7;border-color:#0b5ed7}.panel{border-color:#e8ebf5;border-radius:16px;box-shadow:0 4px 12px #00000005}.filters{padding:21px;gap:14px;margin-bottom:24px}.field label{font-size:11px;color:#71809d;letter-spacing:.6px}.field input,.field select{font-size:13px;min-height:42px}.search-box svg{top:12px}.field-small{flex-basis:123px}.filter-clear{font-size:12px}.cards{gap:24px;margin-bottom:24px}.metric-card{border:0;border-left:5px solid #28a745;border-radius:16px;box-shadow:0 4px 12px #00000014;padding:19px 22px;min-height:120px;align-items:center;gap:13px}.metric-card.moderate{border-left-color:#ffc107}.metric-card.low{border-left-color:#dc3545}.metric-card.unrated{border-left-color:#2b3a8c}.metric-icon,.metric-card.moderate .metric-icon,.metric-card.low .metric-icon,.metric-card.unrated .metric-icon{background:transparent;width:37px;height:40px;border-radius:0}.metric-icon svg{width:31px;height:31px}.metric-card.unrated .metric-icon{color:#2b3a8c}.metric-label{font-size:13px;font-weight:500;color:#28384e}.metric-value{font-size:28px;line-height:1.1;color:#111827;margin-top:5px}.metric-note{font-size:11px;color:#71809d;margin-top:5px}
.evidence-panel{padding:20px 22px;margin-bottom:24px}.evidence-title{display:flex;gap:16px;align-items:center}.evidence-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:13px;background:#eff2ff;color:#2b3a8c;flex-shrink:0}.evidence-title h2{font-size:17px;margin:0 0 6px}.evidence-title p{font-size:13px;color:#71809d;line-height:1.7;margin:0}.evidence-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:15px;margin-top:18px;border-top:1px solid #edf0f7;padding-top:16px}.evidence-grid span{display:block;font-size:11px;color:#71809d;margin-bottom:7px}.evidence-grid strong{display:block;font-size:12px;color:#526585;font-weight:550;line-height:1.6}.run-info{padding:18px 22px;gap:23px}.run-info span{font-size:11px}.run-info strong{font-size:12px}.table-header{padding:21px 22px}.table-header h2{font-size:18px;color:#2b3a8c}.table-header p{font-size:12px}.summary-count{font-size:12px}th{font-size:11px;padding-top:14px;padding-bottom:14px;color:#687a98}td{padding-top:18px;padding-bottom:18px}.item-name{font-size:13px}.item-meta{font-size:11px}.unit-tag{font-size:10px}.test-label{font-size:13px}.test-count{font-size:11px}.error-pair strong,.total-pair strong{font-size:12px}.error-pair span,.total-pair span{font-size:10px}.smape-value{font-size:13px}.pill{font-size:11px;padding:5px 10px}.row-detail-toggle{font-size:11px}.details-grid h3{font-size:11px}.details-grid p,.reason-box{font-size:12px}.page-info,.page-link{font-size:12px}.page-link{min-width:32px;height:32px}.reading-strip{border-radius:8px;background:#cdf4fd;border-color:#9de8f7;margin-top:24px;padding:17px 18px}.reading-strip strong{color:#07566e;font-size:13px}.reading-strip p{color:#0a647a;font-size:12px}.reading-strip svg{color:#13738a}.reading-strip .text-link{color:#07566e}.policy-note{font-size:11px;line-height:1.85}.policy-note button{font-size:11px}.guide-head h2{color:#2b3a8c}.guide-body p,.guide-body li{font-size:13px}.guide-body h3{font-size:15px;color:#2b3a8c}.definition p,.band p{font-size:12px}.reliability-note{background:#fff8e7;border:1px solid #f2dfaf;padding:16px 18px;border-radius:9px;color:#826722;font-size:12px;line-height:1.9;margin-bottom:15px}.reliability-note strong{display:block}.reliability-note .text-link{font-size:12px;color:#9e7212;padding:0}.logout-content{padding:25px;min-width:320px}.logout-content h2{font-size:21px;margin-top:0;color:#2b3a8c}.logout-content p{color:#71809d;margin:13px 0 23px}.logout-content .actions{justify-content:flex-end}.nav-open .overlay{z-index:1999}
@media(min-width:1500px){.content{padding-top:35px}.metric-card{padding:19px 22px}}
@media(max-width:1250px){.profile-role{display:none}.cards{gap:16px}.metric-card{padding:18px 16px;gap:8px}.metric-label{font-size:12px}.metric-note{font-size:10px}.topbar h3{font-size:25px}.topbar h3 small{font-size:14px}}
@media(max-width:991px){.main,body.sidebar-collapsed .main{margin-left:0}.sidebar,.sidebar.collapsed{width:260px;transform:translateX(-100%);visibility:hidden}.nav-open .sidebar,.nav-open .sidebar.collapsed{transform:none;visibility:visible;width:260px}.nav-open .sidebar .system-name{display:block}.nav-open .sidebar .nav-menu a>span{display:inline}.nav-open .sidebar .nav-menu li{margin:6px 12px}.nav-open .sidebar .nav-menu a{justify-content:flex-start;padding:13px 14px;gap:14px}.nav-open .sidebar .logo-area{padding:25px 20px}.nav-open .sidebar .logo-frame{width:100px;height:100px}.nav-open .sidebar .logo{width:90px;height:90px}.nav-open .sidebar .notification-badge{display:inline-flex}.sidebar-toggle{display:none}.icon-btn.menu-button{display:grid}.topbar{padding:0 20px}.topbar h3{font-size:22px}.topbar h3 small{font-size:13px;display:block;margin:3px 0 0}.content{padding:25px 20px}.cards{grid-template-columns:repeat(2,minmax(0,1fr))}.metric-card{flex-direction:row;min-height:110px}.evidence-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.evidence-title h2{font-size:16px}.profile{font-size:12px}.profile-caret{margin-left:0}.details-grid{grid-template-columns:1fr 1fr}}
@media(min-width:992px){.icon-btn.menu-button{display:none}.overlay{display:none!important}}
@media(max-width:575px){.topbar{height:80px;padding:0 13px}.topbar h3{font-size:19px}.topbar h3 small{font-size:12px}.profile{padding-left:5px;gap:5px}.profile>span:not(.profile-caret){max-width:75px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.profile svg{width:17px}.content{padding:20px 14px}.hero{padding:16px}.hero h1{font-size:15px}.hero .actions{flex-wrap:wrap;gap:7px}.hero p{font-size:12px}.cards{gap:12px}.metric-card{padding:14px 11px;gap:6px}.metric-icon{width:24px}.metric-icon svg{width:24px;height:24px}.metric-label{font-size:11px}.metric-note{font-size:10px}.metric-value{font-size:26px}.filters{padding:16px}.field-small{flex-basis:calc(50% - 7px)}.evidence-panel{padding:16px}.evidence-title{gap:11px;align-items:flex-start}.evidence-icon{width:36px;height:36px}.evidence-title h2{font-size:15px}.evidence-title p{font-size:12px}.evidence-grid{gap:12px}.evidence-grid strong{font-size:11px}.reading-strip p{font-size:11px}.details-grid{grid-template-columns:1fr}.details-grid>div:last-child{grid-column:auto}.account-options{min-width:160px}.logout-content{min-width:0}}

</style></head>
<body>
<div class="overlay" id="navOverlay"></div>
<aside class="sidebar" id="accuracyNav" aria-label="Main navigation">
    <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Collapse sidebar" aria-expanded="true"><i><?= accuracyIcon('chevron') ?></i></button>
    <div class="logo-area"><div class="logo-frame"><img src="logo.png" class="logo" alt="Smart Bite Care Logo"></div><div class="system-name">Smart Bite Care</div></div>
    <nav class="nav-menu"><ul>
        <?php foreach($navigation as [$href,$label,$icon]):$active=$role===2?$href==='BranchAdmin_Forecasting.php':$href==='ForecastAccuracy.php'; ?>
        <li><a href="<?= accuracyH($href) ?>" class="<?= $active?'active':'' ?>" <?= $active?'aria-current="page"':'' ?> title="<?= accuracyH($label) ?>"><i><?= accuracyIcon($icon) ?></i><span><?= accuracyH($label) ?></span><?php if($icon==='bell'&&$notificationCount>0): ?><span class="notification-badge"><?= (int)$notificationCount ?></span><?php endif; ?></a></li>
        <?php if($role===2&&$active): ?><li class="accuracy-subnav"><a href="<?= accuracyH(accuracyUrl(['page'=>1])) ?>" aria-current="page" title="Forecast Accuracy"><i><?= accuracyIcon('target') ?></i><span>Forecast Accuracy</span></a></li><?php endif; ?>
        <?php endforeach; ?>
    </ul></nav>
</aside>
<div class="main">
    <header class="topbar"><div class="topbar-left"><button class="icon-btn menu-button" type="button" id="navToggle" aria-label="Open navigation" aria-controls="accuracyNav" aria-expanded="false"><?= accuracyIcon('menu') ?></button><h3>Forecast Accuracy <small><?= accuracyH($branchName) ?></small></h3></div>
        <details class="account-menu" id="accountMenu"><summary class="profile"><?= accuracyIcon('user') ?><span><?= accuracyH($actor['username']) ?></span><span class="profile-role">| <?= $role===1?'Super Admin':'Branch Admin' ?></span><span class="profile-caret">⌄</span></summary><div class="account-options"><a href="Account_ChangePassword.php">Change Password</a><button type="button" id="openLogout">Logout</button></div></details>
    </header>
    <main class="content">
        <div class="hero"><div><h1><?= accuracyIcon('chart') ?>Historical Forecast Evaluation</h1><p>Review the selected test window. Error scores describe past performance; they do not certify future forecasts.</p></div><div class="actions"><button class="btn" type="button" data-open-guide><?= accuracyIcon('book') ?>How to read results</button><?php if($run): ?><a class="btn btn-primary" href="<?= accuracyH(accuracyUrl(['export'=>'csv'])) ?>"><?= accuracyIcon('download') ?>Export results</a><?php endif; ?></div></div>
        <nav class="actions" aria-label="Evaluation views" style="margin-bottom:20px;flex-wrap:wrap"><a class="btn <?= !$rollingView?'btn-primary':'' ?>" href="ForecastAccuracy.php?<?= accuracyH(http_build_query(['branch'=>$branchId])) ?>">Single holdout</a><a class="btn <?= $rollingView?'btn-primary':'' ?>" href="ForecastAccuracy.php?<?= accuracyH(http_build_query(['branch'=>$branchId,'view'=>'rolling'])) ?>">Repeated tests &amp; baselines</a></nav>
        <?php
        // "Last updated · data revision" of the run on screen, and the automatic update state.
        $shownRun=$rollingView?($rolling['run']??null):$run;
        $shownRevision=null;
        if($shownRun){
            if($rollingView){$revisionMap=forecastAutoEvaluationRevisions($conn,$branchId,'reliability_id');$shownRevision=$revisionMap[(int)$shownRun['reliability_id']]??null;}
            else{$shownRevision=isset($shownRun['training_revision'])?(int)$shownRun['training_revision']:null;}
        }
        ?>
        <?php if(!empty($autoState['ready'])): ?>
        <div class="panel" id="autoEvaluationStatus" data-running="<?= !empty($autoState['running'])?'1':'0' ?>" style="padding:14px 20px;display:flex;flex-wrap:wrap;gap:8px 18px;align-items:center">
            <span><strong>Last updated:</strong> <?= $shownRun?accuracyH(date('M j, Y · g:i a',strtotime($shownRun['generated_at']))):'—' ?>
                · <?= $shownRevision!==null?'data revision '.(int)$shownRevision:'data revision not recorded (saved before automatic updates)' ?>
                <span style="color:var(--muted)">(current data revision <?= (int)$autoState['revision'] ?>)</span></span>
            <?php if(!empty($autoState['running'])): ?>
                <span class="pill moderate" id="autoEvaluationRunning">Updating… showing previous results</span>
            <?php elseif(!empty($autoState['last_failed'])): ?>
                <span class="pill low">The last automatic update failed (<?= accuracyH(date('M j, g:i a',strtotime((string)$autoState['last_failed']['finished_at']))) ?>): <?= accuracyH((string)$autoState['last_failed']['message']) ?> Showing previous results.</span>
            <?php elseif(empty($autoState['stale'])): ?>
                <span class="pill">Latest automatic results are up to date with the training data</span>
            <?php endif; ?>
            <?php if(!empty($autoState['last_saved']['message'])): ?><span style="color:var(--muted);font-size:12px"><?= accuracyH((string)$autoState['last_saved']['message']) ?></span><?php endif; ?>
        </div>
        <?php endif; ?>
        <?php if($rollingView):forecastReliabilityRender($rolling,$branches,$branchId,$role);else: ?>
        <?php if($problems): ?><div class="setup-alert">Forecast accuracy setup is incomplete. Preview and apply <code>sources/migrate_forecast_accuracy.php</code> to enable saved results.</div><?php endif; ?>
        <form class="panel filters" method="get" action="ForecastAccuracy.php" id="accuracyFilters">
            <?php if($role===1): ?><div class="field"><label for="branch">Branch</label><select name="branch" id="branch"><?php foreach($branches as $b): ?><option value="<?= accuracyH($b['branch_id']) ?>" <?= $branchId===$b['branch_id']?'selected':'' ?>><?= accuracyH($b['branch_name']) ?></option><?php endforeach; ?></select></div><?php else: ?><input type="hidden" name="branch" value="<?= accuracyH($branchId) ?>"><?php endif; ?>
            <div class="field field-wide"><label for="run">Saved evaluation</label><select name="run" id="run" <?= !$runs?'disabled':'' ?>><?php if(!$runs): ?><option>No evaluations saved yet</option><?php endif; ?><?php foreach($runs as $r): ?><option value="<?= (int)$r['evaluation_id'] ?>" <?= (int)$r['evaluation_id']===$runId?'selected':'' ?>>#<?= (int)$r['evaluation_id'] ?> · <?= accuracyH(date('M j, Y · g:i a',strtotime($r['generated_at']))) ?> · <?= (int)$r['generated_horizon'] ?> days</option><?php endforeach; ?></select></div>
            <div class="field"><label for="method">Test method</label><select name="method" id="method"><?php foreach(['all'=>'All methods','recursive'=>'Forecast test','one_step'=>'Next-day check'] as $v=>$label): ?><option value="<?= $v ?>" <?= $method===$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="field field-small"><label for="horizon">Test horizon</label><select name="horizon" id="horizon"><?php foreach(['all'=>'All horizons','1'=>'1 day','7'=>'7 days','14'=>'14 days','30'=>'30 days'] as $v=>$label): ?><option value="<?= accuracyH($v) ?>" <?= $horizon===(string)$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
            <div class="field field-search"><label for="itemSearch">Item search</label><div class="search-box"><?= accuracyIcon('search') ?><input type="search" name="q" id="itemSearch" value="<?= accuracyH($search) ?>" placeholder="Search a supply…" maxlength="200"></div></div>
            <div class="field field-small"><label for="perPage">Rows per page</label><select id="perPage" name="per_page"><?php foreach([10,20,50] as $size): ?><option value="<?= $size ?>" <?= $perPage===$size?'selected':'' ?>><?= $size ?> rows</option><?php endforeach; ?></select></div>
            <div class="field"><label for="units">Show errors in</label><select id="units" name="units"><option value="display" <?= $unitMode==='display'?'selected':'' ?>>Vials / ampules</option><option value="base" <?= $unitMode==='base'?'selected':'' ?>>Base units (as stored)</option></select></div>
            <button class="btn btn-primary" type="submit">Apply filters</button><a class="filter-clear" href="<?= accuracyH(accuracyUrl(['method'=>'all','horizon'=>'all','q'=>'','page'=>1])) ?>">Reset</a>
        </form>
        <?php if(!$run): ?><section class="panel empty-state"><?= accuracyIcon('chart') ?><h2>No saved accuracy results yet</h2><p>Open Supply Forecasting as this branch's administrator and select a horizon. Its first successful generation after setup saves the test results here. Viewing this report never runs the model or changes stock.</p><a class="btn" href="<?= accuracyH($back) ?>"><?= accuracyIcon('arrow') ?><?= $role===2?'Open supply forecasting':'Back to dashboard' ?></a></section>
        <?php else: ?>
            <?php forecastMetricsRender($overallMetrics,false,$search!==''); ?>
            <section class="panel evidence-panel" aria-label="Reliability evidence">
                <div class="evidence-title"><span class="evidence-icon"><?= accuracyIcon('book') ?></span><div><h2>What these results can establish</h2><p>This saved evaluation uses one historical cutoff. Open Repeated tests &amp; baselines for additional saved evidence.</p></div></div>
                <div class="evidence-grid"><div><span>Chronological holdout</span><strong><?= $chronologicalEvidence?'Saved train/test separation':'Insufficient saved dates' ?></strong></div><div><span>Repeated test windows</span><strong>Not recorded by this evaluator</strong></div><div><span>Baseline comparison</span><strong>Not recorded by this evaluator</strong></div><div><span>Prospective clinic validation</span><strong>Not established by this report</strong></div></div>
            </section>
            <section class="cards" aria-label="Provisional review band totals">
                <?php foreach(['high'=>['High','check','SMAPE ≤ '.accuracyScore(ACCURACY_REVIEW_HIGH_MAX).'%'],'moderate'=>['Moderate','target','SMAPE > '.accuracyScore(ACCURACY_REVIEW_HIGH_MAX).'% to '.accuracyScore(ACCURACY_REVIEW_MODERATE_MAX).'%'],'low'=>['Low','alert','SMAPE > '.accuracyScore(ACCURACY_REVIEW_MODERATE_MAX).'%'],'unrated'=>['Not rated','clock','Insufficient evidence or no demand']] as $key=>$card): ?><div class="metric-card <?= $key ?>" data-band-summary="<?= $key ?>"><span class="metric-icon"><?= accuracyIcon($card[1]) ?></span><div><div class="metric-label"><?= $card[0] ?> review band</div><div class="metric-value"><?= number_format($totals[$key]) ?></div><div class="metric-note"><?= accuracyH($card[2]) ?></div></div></div><?php endforeach; ?>
            </section>
            <section class="panel" aria-label="Saved test results">
                <div class="run-info"><div class="run-id"><?= accuracyIcon('file') ?><div><span>Saved evaluation</span><strong>#<?= (int)$runId ?></strong></div></div><div><span>Training window · <?= number_format((int)$run['training_records']) ?> records</span><strong><?= accuracyH(accuracyPeriod($run['training_start'],$run['training_end'])) ?></strong></div><div><span>Held-out window · <?= number_format((int)$run['testing_records']) ?> records</span><strong><?= accuracyH(accuracyPeriod($run['testing_start'],$run['testing_end'])) ?></strong></div><div><span>Saved in Manila time</span><strong><?= accuracyH(date('M j, Y · g:i a',strtotime($run['generated_at']))) ?></strong></div></div>
                <div class="table-header"><div><h2>Item performance</h2><p>Daily errors use each item's saved base unit. Review bands cover all filtered results.</p></div><span class="summary-count"><?= number_format($totals['total']) ?> test result<?= $totals['total']===1?'':'s' ?></span></div>
                <p class="mobile-table-hint">Swipe the table sideways to see errors, totals and review bands.</p>
                <div class="table-scroll"><table><thead><tr><th scope="col">Medical supply</th><th scope="col">Test window</th><th scope="col">Daily error</th><th scope="col">SMAPE</th><th scope="col">Window total</th><th scope="col">Review band</th></tr></thead><tbody>
                    <?php if(!$rows): ?><tr><td colspan="6" class="empty-cell"><?= accuracyIcon('search') ?><strong>No results match these filters</strong><p>Try a different item name or test method. One-day results use the 1-day horizon; forecast tests use 7, 14 or 30 days.</p></td></tr><?php endif; ?>
                    <?php foreach($rows as $r): $rating=accuracyRating($r);$detailId='test-detail-'.(int)$r['evaluation_item_id']; ?><tr class="result-row" data-evaluation-item="<?= (int)$r['evaluation_item_id'] ?>">
                        <td><span class="item-name"><?= accuracyH($r['item_name']) ?></span><div class="item-meta"><span class="unit-tag"><?= accuracyH($r['base_unit']) ?></span><span>Item #<?= (int)$r['item_id'] ?></span></div></td>
                        <td><div class="test-label"><?= (int)$r['horizon_days'] ?>-day <?= $r['method']==='recursive'?'forecast test':'check' ?></div><div class="test-count"><?= number_format((int)$r['testing_records']) ?> observed day<?= (int)$r['testing_records']===1?'':'s' ?></div></td>
                        <td class="numeric"><div class="error-pair"><span>MAE</span><strong title="<?= accuracyH($r['mae']??'Unavailable') ?>"><?= accuracyH(accuracyScore($r['mae'])) ?></strong><span>RMSE</span><strong title="<?= accuracyH($r['rmse']??'Unavailable') ?>"><?= accuracyH(accuracyScore($r['rmse'])) ?></strong></div></td>
                        <td class="numeric"><span class="smape-value"><?= accuracyH(accuracyScore($r['smape_percent'])) ?><?= $r['smape_percent']!==null?'%':'' ?></span><?php if($r['smape_percent']!==null): ?><div class="smape-track <?= $rating['key'] ?>" aria-hidden="true"><span style="width:<?= number_format(max(0,min(100,(float)$r['smape_percent']/2)),2,'.','') ?>%"></span></div><?php endif; ?></td>
                        <td class="numeric"><div class="total-pair"><span>Actual</span><strong><?= accuracyH(accuracyScore($r['actual_total'])) ?></strong><span>Forecast</span><strong><?= accuracyH(accuracyScore($r['forecast_total'])) ?></strong></div></td>
                        <td class="rating-cell"><span class="pill <?= $rating['key'] ?>" title="<?= accuracyH($rating['note']) ?>"><?= accuracyH($rating['label']) ?></span><button class="row-detail-toggle" type="button" data-detail="<?= $detailId ?>" aria-controls="<?= $detailId ?>" aria-expanded="false">View test details</button></td>
                    </tr><tr class="details-row" id="<?= $detailId ?>" hidden><td colspan="6"><div class="details-grid"><div><h3>Training evidence</h3><p><?= number_format((int)$r['training_records']) ?> model records for this item<br><?= accuracyH(accuracyPeriod($r['training_start'],$r['training_end'])) ?></p></div><div><h3>Held-out observations</h3><p><?= number_format((int)$r['testing_records']) ?> test records<br><?= accuracyH(accuracyPeriod($r['testing_start'],$r['testing_end'])) ?></p></div><div><h3>Reading this result</h3><p><?= accuracyH($rating['note']) ?><?php if($r['mae']!==null): ?><br>Typical daily difference: <?= accuracyH(accuracyScore($r['mae'])) ?> <?= accuracyH($r['base_unit']) ?>.<br>Total absolute error: <?= accuracyH(accuracyScore($r['total_absolute_error'])) ?> <?= accuracyH($r['base_unit']) ?>.<?php endif; ?></p></div></div><?php if($r['reason']): ?><div class="reason-box"><b><?= accuracyH($r['evaluation_status']) ?></b><?= accuracyH($r['reason']) ?></div><?php endif; ?></td></tr><?php endforeach; ?>
                </tbody></table></div>
                <div class="table-footer"><span class="page-info">Showing <?= $first ?>–<?= $last ?> of <?= number_format($totals['total']) ?> results · Page <?= $page ?> of <?= $totalPages ?></span><nav class="pagination" aria-label="Result pages"><?php if($page>1): ?><a class="page-link page-prev" href="<?= accuracyH(accuracyUrl(['page'=>$page-1])) ?>" aria-label="Previous page"><?= accuracyIcon('chevron') ?></a><?php else: ?><span class="page-link page-prev disabled" aria-disabled="true"><?= accuracyIcon('chevron') ?></span><?php endif; ?><?php $previous=0;foreach($pageNumbers as $number): ?><?php if($previous&&$number>$previous+1): ?><span class="page-gap">…</span><?php endif; ?><?php if($number===$page): ?><span class="page-link current" aria-current="page"><?= $number ?></span><?php else: ?><a class="page-link" href="<?= accuracyH(accuracyUrl(['page'=>$number])) ?>" aria-label="Page <?= $number ?>"><?= $number ?></a><?php endif; ?><?php $previous=$number;endforeach; ?><?php if($page<$totalPages): ?><a class="page-link page-next" href="<?= accuracyH(accuracyUrl(['page'=>$page+1])) ?>" aria-label="Next page"><?= accuracyIcon('chevron') ?></a><?php else: ?><span class="page-link page-next disabled" aria-disabled="true"><?= accuracyIcon('chevron') ?></span><?php endif; ?></nav></div>
            </section>
        <?php endif; ?>
        <div class="reading-strip"><?= accuracyIcon('book') ?><p><strong>Read the error, then the evidence.</strong>Start with the review band, compare MAE/RMSE in actual units, and check the test dates and observation count. SMAPE is an error percentage; 10% SMAPE does not mean “90% accurate.”</p><button type="button" class="text-link" data-open-guide>Read the guide →</button></div>
        <div class="reliability-note"><strong>A close total can hide inaccurate daily forecasts.</strong> For example, overestimates on some days and underestimates on others can cancel. Read daily MAE/RMSE and horizon totals separately, then compare repeated tests against a baseline. <button class="text-link" type="button" data-open-guide>How to verify reliability →</button></div>
        <p class="policy-note">High / Moderate / Low are provisional application review bands applied to historical tests, not universal scientific cutoffs or a guarantee of future accuracy. Fewer than <?= ACCURACY_REVIEW_MIN_TEST_RECORDS ?> test observations, no item training records, unavailable tests and zero observed demand stay unrated. Counts refer to test outcomes, not unique items. <button type="button" class="text-link" data-open-guide>See the thresholds and examples.</button> Displayed quantities are rounded for readability; CSV retains saved precision.</p>
        <?php endif; ?>
    </main>
</div>
<dialog id="readingGuide" aria-labelledby="guideTitle"><div class="guide-head"><div><h2 id="guideTitle">How to read forecast results</h2><p>A practical guide to errors, evidence and stock decisions.</p></div><button class="icon-btn" type="button" id="closeGuide" aria-label="Close reading guide"><?= accuracyIcon('close') ?></button></div><div class="guide-body">
    <h3>1. Choose the matching test horizon</h3><p>Use the 7-day forecast test to review a 7-day forecast, and the equivalent 14- or 30-day test for longer forecasts. Next-day checks forecast each holdout day from information known the evening before. Forecast tests predict the whole 7-, 14- or 30-day total directly from the day before the holdout, just like the operational forecast. Neither uses the patient count of the day being forecast. Evaluations saved before version sbc-evaluation-v2 used the earlier recursive model. Comparing different horizons or methods can give a misleading impression.</p>
    <h3>2. Read the provisional review band</h3><div class="guide-bands"><div class="band"><span class="pill high">High</span><strong>SMAPE ≤ <?= accuracyScore(ACCURACY_REVIEW_HIGH_MAX) ?>%</strong><p>Small percentage error on the observed historical test.</p></div><div class="band"><span class="pill moderate">Moderate</span><strong>> <?= accuracyScore(ACCURACY_REVIEW_HIGH_MAX) ?>% to <?= accuracyScore(ACCURACY_REVIEW_MODERATE_MAX) ?>%</strong><p>Review the typical difference in the item's actual unit.</p></div><div class="band"><span class="pill low">Low</span><strong>SMAPE > <?= accuracyScore(ACCURACY_REVIEW_MODERATE_MAX) ?>%</strong><p>Larger error under this policy; review before relying on it.</p></div></div><div class="guide-callout">These thresholds are provisional SmartBiteCare review targets. The clinic should agree on acceptable errors for its supplies; this page does not claim that these bands are universal statistical standards. A band is assigned only to an Available test with at least <?= ACCURACY_REVIEW_MIN_TEST_RECORDS ?> observations, item training records and positive actual demand. That minimum is a display rule, not proof of statistical reliability.</div>
    <h3>3. Check how large the error is in practice</h3><div class="definitions"><div class="definition"><b>MAE · typical daily difference</b><p>MAE of 2 mL means the forecast differed from actual consumption by 2 mL per day on average, without considering whether it was too high or too low.</p></div><div class="definition"><b>RMSE · larger errors have more weight</b><p>RMSE much higher than MAE suggests that some days had unusually large errors. Read it in the same base unit as MAE.</p></div><div class="definition"><b>SMAPE · percentage error</b><p>Lower is better. This system's nonnegative consumption formula ranges from 0% to 200%. It is not an accuracy percentage; do not subtract it from 100.</p></div><div class="definition"><b>Actual vs forecast total</b><p>For example, 100 actual units vs 110 forecast units means the window was overestimated by 10 units. Similar totals can still hide poor day-by-day estimates.</p></div></div>
    <div class="example"><p><b>Example, using the provisional bands:</b> Across a 14-day test, actual usage is 10 mL each day and the forecast is 11 mL each day. MAE is 1 mL, RMSE is 1 mL, and SMAPE is about 9.5238%. Total usage is 140 mL actual vs 154 mL forecast. With item training records, it receives a High band because its percentage error is within the chosen target. You should still review the 1 mL daily error and 14 mL excess total against the clinic's planning needs. This is historical evidence, not a promise about the next 14 days.</p></div>
    <h3>4. Inspect the test evidence</h3><ol><li>Click <b>View test details</b> to see training/test dates and record counts. Earlier dates train the evaluation model; later dates are held out. One holdout window is a limited sample of the item's possible future behaviour.</li><li><b>Not rated / Insufficient History</b> means a complete test was unavailable. Missing dates are not filled with invented zero consumption.</li><li><b>Limited test data</b> means a score exists but fails this page's minimum evidence rule.</li><li><b>No observed demand</b> means the test had zero actual consumption. Even zero error on that window does not demonstrate performance when demand returns.</li><li>For very small or intermittent usage, percentage errors can be hard to interpret. Give attention to MAE/RMSE in actual units and the test observations.</li></ol>
    <h3>Reading repeated tests and baselines</h3><p>Open <b>Repeated tests &amp; baselines</b> to review the separate rolling evaluations. Each starting date retrains the same model using only earlier data. Forecasts use only information known on each starting date (no withheld demand or same-day patient counts); each item uses XGBoost or its recent 28-day average, whichever won on an earlier holdout. The simple comparisons hold the last observed usage or the mean of the last seven observed usage records constant across the test period. Every complete window compares all three methods on the same dates.</p><p><b>Lower MAE than both</b> means XGBoost has less average daily error than both baselines. <b>Mixed comparison</b> means it improves on only one. <b>Baselines match or beat model</b> means its average error provides no advantage over either. With fewer than three complete windows, the comparison stays Limited; zero demand also remains unclassified. These evidence rules do not establish statistical significance or certify future results. Review the window win count, daily evidence and errors in the item's actual unit.</p><p>Positive MAE improvement means less error than the baseline; negative means more. If the baseline's error is zero, percentage improvement is undefined. Scores are pooled within each item and horizon. Different item units are never combined into one MAE. Five starting dates do not become fifteen independent tests when three overlapping horizons are shown.</p>
    <h3>5. What would support a reliability claim?</h3>
    <div class="guide-callout">Single holdout retains its original test. The repeated-test tab shows independently saved rolling tests and paired baseline comparisons, when available. Data verification, planning tolerances and prospective clinic validation remain necessary. Do not change the display thresholds merely to make Low results look High.</div>
    <ol><li><b>Verify the actual data.</b> Check that consumption comes from real clinic usage, dates are complete, and every item's quantity uses a consistent base unit. Imported history needs a known origin; a large record count alone is not evidence of authenticity.</li><li><b>Repeat tests at several later cutoffs.</b> For each cutoff, train using only earlier observations and test the next 7, 14 or 30 days. Summarise performance per item and horizon. The repeated-test evaluator stores multiple starting dates and marks incomplete windows unavailable.</li><li><b>Compare with simple baselines.</b> Test XGBoost against last-observed usage, a recent historical mean or a weekly seasonal forecast on the same dates. A more complicated model is useful only if its performance provides an advantage for the intended task. The repeated-test tab stores last-usage and recent-mean baseline scores and their daily evidence.</li><li><b>Measure the intended planning outcome.</b> Review daily error, total horizon demand error and persistent over/under-forecasting separately. The clinic needs acceptable error limits in actual units; SMAPE review bands do not replace that decision.</li><li><b>Validate prospectively.</b> Preserve a forecast before its horizon begins, then compare it with newly verified actual daily consumption. Repeat over several complete windows and check missed shortages and unnecessary reorder alerts. Refreshing a forecast later must not overwrite the original evidence.</li></ol>
    <p>Only after these tests can the evidence support a limited claim such as “this item performed better than the chosen baseline on these dates and met the clinic's planning tolerance.” A forecast is still an estimate, not a guaranteed outcome.</p>
    <h3>6. Read the supply forecast separately</h3><p>On Supply Forecasting, <b>forecasted consumption</b> is estimated total usage across the selected future horizon, in the item's base unit. <b>Current stock</b> is usable stock; expired quantities are excluded. <b>Recommended reorder</b> is the suggested top-up to cover expected usage and the minimum stock target.</p><div class="definitions"><div class="definition"><b>Sufficient</b><p>The forecast's stock policy suggests no top-up. This describes stock coverage, not model accuracy.</p></div><div class="definition"><b>Reorder Needed</b><p>A top-up is needed to cover forecast usage and finish at the minimum stock target.</p></div><div class="definition"><b>Shortage Risk</b><p>Stock is zero or may be insufficient within the forecast or configured restocking window. Review supply availability promptly.</p></div><div class="definition"><b>Low Stock</b><p>Current positive usable stock is at or below the minimum. This is the current stock condition, separate from future forecast status.</p></div></div>
    <details><summary>Evaluation method and reference</summary><p>The evaluation model uses an 80/20 chronological split of usable model dates. The production model is then trained on all usable history. Item units and names on this report come from saved snapshots. XGBoost version for this evaluation: <?= accuracyH($run['model_version']??'No saved evaluation') ?>. Review policy: <?= ACCURACY_REVIEW_VERSION ?>.</p><p>For background on held-out forecast tests and error measures, see <a href="https://otexts.com/fpp3/toolbox.html#evaluating-point-forecast-accuracy" target="_blank" rel="noopener noreferrer">Forecasting: Principles and Practice</a>. That reference does not prescribe this page's provisional review bands.</p></details>
</div></dialog>
<dialog id="logoutDialog" aria-labelledby="logoutTitle"><div class="logout-content"><h2 id="logoutTitle">Confirm Logout</h2><p>Are you sure you want to log out?</p><div class="actions"><button class="btn" type="button" id="cancelLogout">Cancel</button><a class="btn btn-primary" href="logout.php">Logout</a></div></div></dialog>
<script>
const guide=document.getElementById('readingGuide');
document.querySelectorAll('[data-open-guide]').forEach(button=>button.addEventListener('click',()=>guide.showModal()));
document.getElementById('closeGuide').addEventListener('click',()=>guide.close());
guide.addEventListener('click',event=>{const rect=guide.getBoundingClientRect();if(event.target===guide&&(event.clientX<rect.left||event.clientX>rect.right||event.clientY<rect.top||event.clientY>rect.bottom))guide.close();});
document.querySelectorAll('[data-detail]').forEach(button=>button.addEventListener('click',()=>{const row=document.getElementById(button.dataset.detail);const expanded=button.getAttribute('aria-expanded')==='true';row.hidden=expanded;button.setAttribute('aria-expanded',String(!expanded));button.textContent=expanded?'View test details':'Hide test details';}));
const navButton=document.getElementById('navToggle');
function closeNavigation(){document.body.classList.remove('nav-open');navButton.setAttribute('aria-expanded','false');}
navButton.addEventListener('click',()=>{const open=document.body.classList.toggle('nav-open');navButton.setAttribute('aria-expanded',String(open));});
document.getElementById('navOverlay').addEventListener('click',closeNavigation);
const sidebar=document.getElementById('accuracyNav');
const sidebarToggle=document.getElementById('sidebarToggle');
function setSidebarCollapsed(collapsed){sidebar.classList.toggle('collapsed',collapsed);document.body.classList.toggle('sidebar-collapsed',collapsed);sidebarToggle.setAttribute('aria-expanded',String(!collapsed));sidebarToggle.setAttribute('aria-label',collapsed?'Expand sidebar':'Collapse sidebar');}
try{setSidebarCollapsed(localStorage.getItem('smartbitecare-sidebar-collapsed')==='1');}catch{}
sidebarToggle.addEventListener('click',()=>{if(window.innerWidth<=991){closeNavigation();return;}const collapsed=!sidebar.classList.contains('collapsed');setSidebarCollapsed(collapsed);try{localStorage.setItem('smartbitecare-sidebar-collapsed',collapsed?'1':'0');}catch{}});
document.getElementById('openLogout').addEventListener('click',()=>{document.getElementById('accountMenu').open=false;document.getElementById('logoutDialog').showModal();});
document.getElementById('cancelLogout').addEventListener('click',()=>document.getElementById('logoutDialog').close());
document.addEventListener('keydown',event=>{if(event.key==='Escape')closeNavigation();});
const branchSelect=document.getElementById('branch');
if(branchSelect)branchSelect.addEventListener('change',()=>{document.getElementById('run').disabled=true;document.getElementById('accuracyFilters').requestSubmit();});
</script>
<script>
// While the background evaluation runs, check every 10 seconds and reload when it finishes.
(function(){
    var box=document.getElementById('autoEvaluationStatus');
    if(!box||box.dataset.running!=='1')return;
    var url='ForecastAccuracy.php?auto_status=1&branch='+encodeURIComponent(<?= json_encode($branchId) ?>);
    var timer=setInterval(function(){
        fetch(url,{cache:'no-store',headers:{Accept:'application/json'}}).then(function(r){return r.json();}).then(function(s){
            if(!s.running){clearInterval(timer);window.location.reload();}
        }).catch(function(){});
    },10000);
})();
</script>
</body></html>
