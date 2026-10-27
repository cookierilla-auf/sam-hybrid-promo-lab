<?php
require __DIR__.'/config.php';
if ($argc!==2) { fwrite(STDERR,"Usage: php enqueue.php CAMPAIGN\n"); exit(1); }
$publisher=new Lab\QueuePublisher((new Lab\Database())->connect(),Lab\Settings::queue(),
    Lab\Settings::required('SQS_QUEUE_URL'),new Lab\ActivityLogger());
echo 'Published '.$publisher->publishPending($argv[1])." pending requests.\n";
