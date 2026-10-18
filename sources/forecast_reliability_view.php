<?php
declare(strict_types=1);
require_once __DIR__.'/forecast_reliability_helper.php';
require_once __DIR__.'/forecast_metrics_summary.php';
require_once __DIR__.'/forecast_accuracy_units.php';

function forecastReliabilityUrl(array $changes=[]): string
{
    global $branchId,$horizon,$search,$perPage,$rolling;
    $params=['view'=>'rolling','branch'=>$branchId,'horizon'=>$horizon,'q'=>$search,'per_page'=>$perPage];
    if(isset($rolling['run']['reliability_id']))$params['rolling_run']=(int)$rolling['run']['reliability_id'];
    elseif(isset($_GET['rolling_run']))$params['rolling_run']=(string)$_GET['rolling_run'];
    if(forecastAccuracyUnitMode()==='base')$params['units']='base';
    return 'ForecastAccuracy.php?'.http_build_query(array_replace($params,$changes));
}

function forecastReliabilityLoad(mysqli $conn,string $branch,string $search,string $horizon,int $size,int $page): array
{
    $data=['problems'=>forecastReliabilitySchemaProblems($conn),'runs'=>[],'run'=>null,'rows'=>[],
        'total'=>0,'pages'=>1,'page'=>1,'details'=>[],'overall_metrics'=>['overall'=>[],'by_unit'=>[]],
        'summary'=>['better'=>0,'mixed'=>0,'baseline'=>0,'limited'=>0]];
    if($data['problems']||$branch==='')return $data;
    $stmt=accuracyQuery($conn,'SELECT * FROM forecast_reliability_runs WHERE branch_id=? ORDER BY reliability_id DESC LIMIT 50','s',[$branch]);
    $data['runs']=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    $id=$_GET['rolling_run']??($data['runs'][0]['reliability_id']??null);
    if($id===null)return $data;
    if(!preg_match('/^[1-9][0-9]*$/D',(string)$id)||strlen((string)$id)>18){http_response_code(400);exit('Invalid rolling evaluation selection.');}
    $id=(int)$id;
    $stmt=accuracyQuery($conn,'SELECT * FROM forecast_reliability_runs WHERE reliability_id=? AND branch_id=?','is',[$id,$branch]);
    $data['run']=$stmt->get_result()->fetch_assoc();$stmt->close();
    if(!$data['run']){http_response_code(404);exit('Rolling evaluation was not found in this branch.');}
    if(!array_filter($data['runs'],static fn($r)=>(int)$r['reliability_id']===$id))array_unshift($data['runs'],$data['run']);
    $parts=['reliability_id=?'];$types='i';$params=[$id];
    if(in_array($horizon,['7','14','30'],true)){$parts[]='horizon_days=?';$types.='i';$params[]=(int)$horizon;}
    if($search!==''){$parts[]="item_name LIKE ? ESCAPE '!'";$types.='s';$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$search).'%';}
    $where=' FROM forecast_reliability_windows WHERE '.implode(' AND ',$parts);
    // Use every matching window; do not aggregate just the visible item page.
    $stmt=accuracyQuery($conn,'SELECT item_id,base_unit,horizon_days,status,training_records,observed_records,xgb_mae,xgb_rmse,xgb_smape_percent,last_mae,last_rmse,last_smape_percent,mean_mae,mean_rmse,mean_smape_percent'.$where,$types,$params);
    // Errors in vials/ampules by default (converted per item); saved rows are never changed.
    $unitMode=forecastAccuracyUnitMode();$unitMap=forecastAccuracyUnitMap($conn);
    $data['unit_mode']=$unitMode;
    $data['overall_metrics']=forecastMetricsSummarize($stmt->get_result(),true,$unitMap,$unitMode);$stmt->close();
    $valid="status='Available' AND observed_records=horizon_days AND training_records>0";
    $aggregates="SELECT item_id,item_name,base_unit,horizon_days,COUNT(*) AS attempted_windows,
        SUM(CASE WHEN $valid THEN 1 ELSE 0 END) AS complete_windows,
        COALESCE(SUM(CASE WHEN $valid THEN actual_total ELSE 0 END),0) AS actual_total,
        SUM(CASE WHEN $valid AND xgb_mae<last_mae AND xgb_mae<mean_mae THEN 1 ELSE 0 END) AS wins";
    foreach(['xgb','last','mean'] as $method){
        $aggregates.=",AVG(CASE WHEN $valid THEN {$method}_mae END) AS {$method}_mae";
        $aggregates.=",SQRT(AVG(CASE WHEN $valid THEN POW({$method}_rmse,2) END)) AS {$method}_rmse";
        $aggregates.=",AVG(CASE WHEN $valid THEN {$method}_smape_percent END) AS {$method}_smape_percent";
    }
    $grouped=$aggregates.$where.' GROUP BY item_id,item_name,base_unit,horizon_days';
    // All scores within a group have the same horizon length: these averages
    // equal pooled daily MAE/SMAPE; RMSE pools squared errors before sqrt.
    $stmt=accuracyQuery($conn,$grouped.' ORDER BY item_name,item_id,horizon_days',$types,$params);
    $all=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();
    foreach($all as $r){[$key,$label]=forecastReliabilityComparison($r);$bucket=['high'=>'better','moderate'=>'mixed','low'=>'baseline','unrated'=>'limited'][$key];$data['summary'][$bucket]++;}
    $data['total']=count($all);$data['pages']=max(1,(int)ceil($data['total']/$size));$data['page']=min($page,$data['pages']);
    $stmt=accuracyQuery($conn,$grouped.' ORDER BY item_name,item_id,horizon_days LIMIT ? OFFSET ?',$types.'ii',[...$params,$size,($data['page']-1)*$size]);
    $data['rows']=array_map(static fn(array $r):array=>forecastAccuracyConvertRow($r,$unitMap,$unitMode,true),$stmt->get_result()->fetch_all(MYSQLI_ASSOC));$stmt->close();
    if(isset($_GET['export']))forecastReliabilityExport($conn,$data['run'],$where,$types,$params,(string)$_GET['export']);
    if(isset($_GET['detail_item'],$_GET['detail_horizon'])){
        $item=filter_var($_GET['detail_item'],FILTER_VALIDATE_INT);$days=filter_var($_GET['detail_horizon'],FILTER_VALIDATE_INT);
        if(!$item||!in_array($days,[7,14,30],true)){http_response_code(400);exit('Invalid window details selection.');}
        $stmt=accuracyQuery($conn,'SELECT * FROM forecast_reliability_windows WHERE reliability_id=? AND item_id=? AND horizon_days=? ORDER BY fold_number','iii',[$id,$item,$days]);
        $data['details']=array_map(static fn(array $r):array=>forecastAccuracyConvertRow($r,$unitMap,$unitMode,true),$stmt->get_result()->fetch_all(MYSQLI_ASSOC));$stmt->close();
        if(!$data['details']){http_response_code(404);exit('Item window details were not found.');}
    }
    return $data;
}

function forecastReliabilityExport(mysqli $conn,array $run,string $where,string $types,array $params,string $kind): void
{
    if(!in_array($kind,['csv','daily'],true)){http_response_code(400);exit('Choose a scores or daily CSV export.');}
    $stmt=accuracyQuery($conn,'SELECT *'.$where.' ORDER BY item_name,item_id,horizon_days,fold_number',$types,$params);
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="forecast-rolling-'.(int)$run['reliability_id'].'-'.$kind.'.csv"');header('Cache-Control: no-store');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    $meta=['Evaluation ID','Branch','Saved at (Manila)','Version','Source hash','Code hash','XGBoost version','Window','Item ID','Item','Base unit','Horizon days','Origin date'];
    if($kind==='daily')$header=[...$meta,'Date','Actual usage','XGBoost forecast','Last-usage forecast','Recent-7-observation-mean forecast'];
    else{
        $header=[...$meta,'Training records','Training start','Training end','Testing start','Testing end','Observed days','Status','Reason','Actual total'];
        foreach(['XGBoost','Last usage','Recent mean'] as $name)$header=[...$header,$name.' MAE',$name.' RMSE',$name.' SMAPE percent',$name.' forecast total'];
    }
    fputcsv($out,$header);
    $write=static function(array $values)use($out):void{foreach($values as &$v)if(is_string($v)&&preg_match('/^[\s]*[=+@-]/u',$v))$v="'".$v;unset($v);fputcsv($out,$values);};
    foreach($stmt->get_result() as $r){
        $values=[$run['reliability_id'],$run['branch_id'],$run['generated_at'],$run['evaluation_version'],$run['source_hash'],$run['code_hash'],$run['model_version'],$r['fold_number'],$r['item_id'],$r['item_name'],$r['base_unit'],$r['horizon_days'],$r['origin_date']];
        if($kind==='daily'){
            $daily=json_decode($r['daily_results'],true,512,JSON_THROW_ON_ERROR);
            foreach($daily as $d)$write([...$values,$d['date'],$d['actual'],$d['xgb'],$d['last'],$d['mean']]);
        }else{
            foreach(['training_records','training_start','training_end','testing_start','testing_end','observed_records','status','reason','actual_total'] as $f)$values[]=$r[$f];
            foreach(['xgb','last','mean'] as $m)foreach(['mae','rmse','smape_percent','forecast_total'] as $f)$values[]=$r[$m.'_'.$f];
            $write($values);
        }
    }
    $stmt->close();fclose($out);exit;
}

function forecastReliabilityRender(array $data,array $branches,string $branchId,int $role): void
{
    global $horizon,$search,$perPage;
    $run=$data['run'];$details=$run?json_decode($run['details'],true,512,JSON_THROW_ON_ERROR):[];
    ?>
    <?php if($data['problems']): ?><div class="setup-alert">Repeated-test setup is incomplete. Follow INSTALL-AND-TEST.md to create the separate reliability tables.</div><?php endif; ?>
    <form class="panel filters" method="get" id="accuracyFilters" action="ForecastAccuracy.php">
        <input type="hidden" name="view" value="rolling">
        <?php if($role===1): ?><div class="field"><label for="branch">Branch</label><select id="branch" name="branch"><?php foreach($branches as $b): ?><option value="<?= accuracyH($b['branch_id']) ?>" <?= $b['branch_id']===$branchId?'selected':'' ?>><?= accuracyH($b['branch_name']) ?></option><?php endforeach; ?></select></div><?php else: ?><input type="hidden" name="branch" value="<?= accuracyH($branchId) ?>"><?php endif; ?>
        <div class="field field-wide"><label for="run">Saved rolling evaluation</label><select id="run" name="rolling_run" <?= !$data['runs']?'disabled':'' ?>><?php if(!$data['runs']): ?><option>No rolling evaluations yet</option><?php endif; ?><?php foreach($data['runs'] as $r): ?><option value="<?= (int)$r['reliability_id'] ?>" <?= $run&&(int)$r['reliability_id']===(int)$run['reliability_id']?'selected':'' ?>>#<?= (int)$r['reliability_id'] ?> · <?= accuracyH(date('M j, Y · g:i a',strtotime($r['generated_at']))) ?> · <?= (int)$r['requested_windows'] ?> windows</option><?php endforeach; ?></select></div>
        <div class="field"><label for="horizon">Test horizon</label><select id="horizon" name="horizon"><?php foreach(['all'=>'All horizons','7'=>'7 days','14'=>'14 days','30'=>'30 days'] as $v=>$label): ?><option value="<?= accuracyH($v) ?>" <?= $horizon===(string)$v?'selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></div>
        <div class="field field-search"><label for="itemSearch">Item search</label><input id="itemSearch" type="search" name="q" value="<?= accuracyH($search) ?>" placeholder="Search a supply…" maxlength="200"></div>
        <div class="field field-small"><label for="perPage">Rows per page</label><select id="perPage" name="per_page"><?php foreach([10,20,50] as $size): ?><option value="<?= $size ?>" <?= $size===$perPage?'selected':'' ?>><?= $size ?> rows</option><?php endforeach; ?></select></div>
        <?php $unitMode=forecastAccuracyUnitMode(); ?><div class="field"><label for="units">Show errors in</label><select id="units" name="units"><option value="display" <?= $unitMode==='display'?'selected':'' ?>>Vials / ampules</option><option value="base" <?= $unitMode==='base'?'selected':'' ?>>Base units (as stored)</option></select></div>
        <button class="btn btn-primary" type="submit">Apply filters</button><a class="filter-clear" href="<?= accuracyH(forecastReliabilityUrl(['q'=>'','horizon'=>'all','page'=>1])) ?>">Reset</a>
    </form>
    <?php if(!$run): ?><section class="panel empty-state"><?= accuracyIcon('clock') ?><h2>No saved rolling evaluation yet</h2><p>Run the evaluation command in INSTALL-AND-TEST.md for this branch. Opening this report only reads saved evidence.</p></section><?php return;endif; ?>
    <?php forecastMetricsRender($data['overall_metrics'],true,$search!==''); ?>
    <section class="panel evidence-panel"><div class="evidence-title"><span class="evidence-icon"><?= accuracyIcon('book') ?></span><div><h2>Repeated tests with matching baselines</h2><p>Every complete comparison uses the same actual dates and quantities for all three methods.</p></div></div><div class="evidence-grid"><div><span>Historical starting dates</span><strong><?= (int)$run['requested_windows'] ?> requested · 30 days apart</strong></div><div><span>Training information</span><strong>Only records through each starting date</strong></div><div><span>Simple comparisons</span><strong>Last usage + recent mean</strong></div><div><span>Future clinic validation</span><strong>Still requires forecasts saved in advance</strong></div></div></section>
    <section class="cards" aria-label="Baseline comparison totals"><?php foreach(['better'=>['Lower MAE than both','high','check'],'mixed'=>['Mixed comparison','moderate','target'],'baseline'=>['Baselines match or beat model','low','alert'],'limited'=>['Limited or no demand','unrated','clock']] as $key=>$card): ?><div class="metric-card <?= $card[1] ?>"><span class="metric-icon"><?= accuracyIcon($card[2]) ?></span><div><div class="metric-label"><?= $card[0] ?></div><div class="metric-value"><?= (int)$data['summary'][$key] ?></div><div class="metric-note">Item / horizon groups across filters</div></div></div><?php endforeach; ?></section>
    <section class="panel rolling-results"><div class="run-info"><div><span>Rolling evaluation</span><strong>#<?= (int)$run['reliability_id'] ?> · <?= accuracyH(date('M j, Y · g:i a',strtotime($run['generated_at']))) ?> Manila</strong></div><div><span>Observed history</span><strong><?= accuracyH(accuracyPeriod($run['data_start'],$run['data_end'])) ?></strong></div><div><span>Historical input sources</span><strong><?php $sources=[];foreach(($details['sources']??[]) as $name=>$count)$sources[]=str_replace('_',' ',(string)$name).': '.number_format((int)$count);echo accuracyH(implode(' · ',$sources)); ?></strong></div></div><div class="table-header rolling-table-header"><div><h2>Paired model and baseline errors</h2><p>Lower MAE is better. Errors are shown in <?= forecastAccuracyUnitMode()==='base'?'each item\'s base unit, exactly as stored':'each item\'s counting unit (vials, ampules); the saved results are in base units' ?>. No errors are pooled across different items or horizons.</p></div><div class="actions"><a class="btn" href="<?= accuracyH(forecastReliabilityUrl(['export'=>'csv'])) ?>">Export window scores</a><a class="btn" href="<?= accuracyH(forecastReliabilityUrl(['export'=>'daily'])) ?>">Export daily evidence</a></div></div>
    <p class="mobile-table-hint">Swipe the table sideways to compare methods.</p><div class="table-scroll"><table><thead><tr><th>Medical supply</th><th>Complete windows</th><th>XGBoost MAE</th><th>Last usage MAE</th><th>Recent mean MAE</th><th>Comparison</th></tr></thead><tbody>
    <?php if(!$data['rows']): ?><tr><td class="empty-cell" colspan="6"><strong>No results match these filters</strong></td></tr><?php endif; ?>
    <?php foreach($data['rows'] as $r):[$key,$label]=forecastReliabilityComparison($r); ?><tr class="result-row" data-rolling-item="<?= (int)$r['item_id'] ?>" data-horizon="<?= (int)$r['horizon_days'] ?>"><td><span class="item-name"><?= accuracyH($r['item_name']) ?></span><div class="item-meta"><span class="unit-tag"><?= accuracyH($r['base_unit']) ?></span><?= (int)$r['horizon_days'] ?>-day horizon</div></td><td><b><?= (int)$r['complete_windows'] ?> / <?= (int)$r['attempted_windows'] ?></b><div class="test-count"><?= (int)$r['wins'] ?> windows with lower model MAE than both</div></td><td class="numeric"><b><?= accuracyH(accuracyScore($r['xgb_mae'])) ?></b><div class="test-count">RMSE <?= accuracyH(accuracyScore($r['xgb_rmse'])) ?><br>SMAPE <?= accuracyH(accuracyScore($r['xgb_smape_percent'])) ?><?= $r['xgb_smape_percent']!==null?'%':'' ?></div></td><td class="numeric"><?= accuracyH(accuracyScore($r['last_mae'])) ?><div class="test-count">Model MAE improvement:<br><?= accuracyH(forecastReliabilitySkill($r['last_mae'],$r['xgb_mae'])) ?></div></td><td class="numeric"><?= accuracyH(accuracyScore($r['mean_mae'])) ?><div class="test-count">Model MAE improvement:<br><?= accuracyH(forecastReliabilitySkill($r['mean_mae'],$r['xgb_mae'])) ?></div></td><td><span class="pill <?= $key ?>"><?= accuracyH($label) ?></span><a class="row-detail-toggle" href="<?= accuracyH(forecastReliabilityUrl(['detail_item'=>$r['item_id'],'detail_horizon'=>$r['horizon_days'],'page'=>$data['page']])) ?>#windowDetails">View windows and daily evidence</a></td></tr><?php endforeach; ?>
    </tbody></table></div><div class="table-footer"><span class="page-info">Showing <?= $data['total']?($data['page']-1)*$perPage+1:0 ?>–<?= min($data['page']*$perPage,$data['total']) ?> of <?= $data['total'] ?> item / horizon groups · Page <?= $data['page'] ?> of <?= $data['pages'] ?></span><nav class="pagination" aria-label="Rolling result pages"><?php $numbers=array_unique([1,...range(max(1,$data['page']-2),min($data['pages'],$data['page']+2)),$data['pages']]);sort($numbers);$lastNumber=0;foreach($numbers as $number): ?><?php if($lastNumber&&$number>$lastNumber+1): ?><span class="page-gap">…</span><?php endif; ?><a class="page-link <?= $number===$data['page']?'current':'' ?>" <?= $number===$data['page']?'aria-current="page"':'' ?> href="<?= accuracyH(forecastReliabilityUrl(['page'=>$number])) ?>"><?= $number ?></a><?php $lastNumber=$number;endforeach; ?></nav></div></section>
    <div class="reading-strip"><?= accuracyIcon('book') ?><p><strong>How to interpret the comparison</strong>Positive MAE improvement means the model has less error than that baseline; negative means more error. For example, baseline MAE 2 and model MAE 1 gives 50% error reduction. This is not “50% forecast accuracy.” A baseline with zero error has no defined percentage improvement.</p></div>
    <p class="policy-note">At least three complete windows with positive demand are required for a comparison label; this is a presentation rule, not a confidence test. Read win counts as well as average errors. Test blocks at the same horizon do not overlap; 7-, 14- and 30-day horizons within a block overlap. Historical source labels do not establish that imported data is authentic. <?= (int)($details['missing_unit_snapshots']??0) ?> records lack saved unit snapshots and need verification. Historical success does not establish future performance.</p>
    <?php if($data['details']): ?><section class="panel" id="windowDetails"><div class="table-header"><div><h2><?= accuracyH($data['details'][0]['item_name']) ?> · <?= (int)$data['details'][0]['horizon_days'] ?>-day window evidence</h2><p>Expand a window to inspect its saved daily actuals and forecasts (in <?= accuracyH($data['details'][0]['base_unit']) ?>). Totals sum those same daily rows.</p></div></div><?php foreach($data['details'] as $w): ?><details class="rolling-window" style="padding:18px 22px;border-top:1px solid var(--border)"><summary style="cursor:pointer;font-weight:600">Window <?= (int)$w['fold_number'] ?> · <?= accuracyH(accuracyPeriod($w['testing_start'],$w['testing_end'])) ?> · <?= accuracyH($w['status']) ?></summary><p class="policy-note">Training: <?= (int)$w['training_records'] ?> item model records · <?= accuracyH(accuracyPeriod($w['training_start'],$w['training_end'])) ?>. Starting date: <?= accuracyH($w['origin_date']) ?>.</p><?php if($w['reason']): ?><p class="reason-box"><?= accuracyH($w['reason']) ?></p><?php else: ?><div class="table-scroll"><table><thead><tr><th>Method</th><th>MAE</th><th>RMSE</th><th>SMAPE</th><th>Forecast total</th><th>Actual total</th><th>Signed total difference</th></tr></thead><tbody><?php foreach(['xgb'=>'XGBoost','last'=>'Last usage','mean'=>'Recent mean'] as $m=>$label): ?><tr><td><?= $label ?></td><?php foreach(['mae','rmse','smape_percent','forecast_total'] as $f): ?><td><?= accuracyH(accuracyScore($w[$m.'_'.$f])) ?><?= $f==='smape_percent'?'%':'' ?></td><?php endforeach; ?><td><?= accuracyH(accuracyScore($w['actual_total'])) ?></td><td><?= accuracyH(accuracyScore((float)$w[$m.'_forecast_total']-(float)$w['actual_total'])) ?></td></tr><?php endforeach; ?></tbody></table></div><div class="table-scroll"><table><thead><tr><th>Date</th><th>Actual usage</th><th>XGBoost</th><th>Last usage</th><th>Recent mean</th></tr></thead><tbody><?php foreach(json_decode($w['daily_results'],true,512,JSON_THROW_ON_ERROR) as $d): ?><tr><td><?= accuracyH($d['date']) ?></td><?php foreach(['actual','xgb','last','mean'] as $f): ?><td><?= accuracyH(accuracyScore(forecastAccuracyConvert($d[$f],(float)($w['unit_scale']??1)))) ?></td><?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></details><?php endforeach; ?></section><?php endif; ?>
    <?php
}
