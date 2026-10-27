<?php
require __DIR__.'/config.php';
$logger=new Lab\ActivityLogger();
$db=(new Lab\Database())->connect();
$worker=new Lab\QueueWorker(Lab\Settings::queue(),Lab\Settings::required('SQS_QUEUE_URL'),
    Lab\Settings::processor($db,$logger),$logger);
echo "One worker started; local events are in logs/events.jsonl. Stop with Ctrl+C.\n";
$worker->run((int)($argv[1]??0),(int)($argv[2]??0));
