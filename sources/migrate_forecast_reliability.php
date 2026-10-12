<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$args=array_slice($argv,1);
if(in_array('--help',$args,true)){echo "Usage: php sources/migrate_forecast_reliability.php [--apply]\nDefault: read-only preview.\n";exit;}
foreach($args as $arg)if($arg!=='--apply'){fwrite(STDERR,"Unknown argument. Use --help.\n");exit(1);}
require_once __DIR__.'/db_connect.php';
require_once __DIR__.'/forecast_reliability_helper.php';
$completed=0;
try{
    $problems=forecastReliabilitySchemaProblems($conn,true);
    if($problems)throw new RuntimeException('Existing definitions need review: '.implode('; ',$problems));
    $plan=[];
    foreach(forecastReliabilityTableDefinitions() as $table=>$definition){
        $stmt=$conn->prepare('SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
        $stmt->bind_param('s',$table);$stmt->execute();$exists=(int)$stmt->get_result()->fetch_assoc()['total'];$stmt->close();
        if(!$exists)$plan[$table]=$definition;
    }
    $apply=in_array('--apply',$args,true);echo ($apply?'APPLY':'PREVIEW — read-only')."\n";
    if(!$plan)echo "No migration changes are needed.\n";
    foreach($plan as $table=>$definition){echo '- Create '.$table."\n";if($apply){$conn->query($definition);$completed++;}}
    if($apply){if(forecastReliabilitySchemaProblems($conn))throw new RuntimeException('Post-migration verification failed.');echo "Reliability tables are ready. Existing records were retained.\n";}
    else echo "No changes were made. Add --apply to create the two reliability tables.\n";
}catch(Throwable $e){
    fwrite(STDERR,'Reliability setup stopped: '.$e->getMessage()."\n".($completed?"Some schema steps completed. Correct the problem and rerun; DDL cannot roll back together.\n":"No migration changes were applied.\n"));exit(1);
}
