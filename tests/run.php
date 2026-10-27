<?php
declare(strict_types=1);
require dirname(__DIR__).'/config.php';

use Aws\MockHandler;
use Aws\Result;
use Aws\Sqs\SqsClient;
use Lab\{ActivityLogger,CampaignPreparer,CustomerSeeder,Database,EmailHistory,LocalDatabaseTools,MailGateway,MarketingProcessor,QueuePublisher,QueueWorker};

// Destructive setup is restricted to a separately named test database.
$testDatabase=getenv('LAB_TEST_DB_NAME')?:'marketing_lab_test';
if (!str_ends_with($testDatabase,'_test')) { throw new RuntimeException('Test database name must end in _test'); }
putenv('DB_NAME='.$testDatabase);
$db=(new Database())->connect();
$db->exec('DROP TABLE IF EXISTS marketing_outbox; DROP TABLE IF EXISTS sent_marketing_emails; DROP TABLE IF EXISTS customers');
(new LocalDatabaseTools($db))->initialize(dirname(__DIR__).'/schema.sql');
$log=__DIR__.'/.runtime/events-'.bin2hex(random_bytes(5)).'.jsonl';
$logger=new ActivityLogger($log);
$passed=0;
function check(bool $condition,string $label):void { global $passed; if (!$condition) {throw new RuntimeException('FAIL: '.$label);} echo 'PASS: '.$label."\n"; $passed++; }
function rejects(callable $action,string $label):void { try {$action();} catch (Throwable $e) {check(true,$label); return;} check(false,$label); }
function mockClient(array $responses):array {
    $mock=new MockHandler($responses);
    $client=new SqsClient(['region'=>'ap-southeast-2','version'=>'2012-11-05',
        'credentials'=>['key'=>'fixture','secret'=>'fixture'],'handler'=>$mock,'retries'=>0]);
    return [$client,$mock];
}
function job(string $campaign,string $customer='CUST-00001'):array {
    return ['requestId'=>bin2hex(random_bytes(16)),'campaignId'=>$campaign,'customerId'=>$customer,
        'promoCode'=>'PROMO15','subject'=>'Fixture subject','body'=>'Fixture body'];
}
function message(array $job):array {return ['MessageId'=>'fixture-sqs-'.bin2hex(random_bytes(4)),
    'ReceiptHandle'=>'fixture-current-receipt-handle','Body'=>json_encode($job,JSON_THROW_ON_ERROR)];}
function outcome(PDO $db,string $campaign):array {
    $q=$db->prepare('SELECT * FROM sent_marketing_emails WHERE campaign_id=?'); $q->execute([$campaign]); return $q->fetch()?:[];
}
$sender=new class implements MailGateway {
    public int $calls=0;
    public bool $fail=false;
    public function send(array $customer,array $job):string {
        $this->calls++; if ($this->fail) {throw new RuntimeException('Simulated SMTP failure');} return '<fixture@example.com>';
    }
};
$history=new EmailHistory($db);
$processor=new MarketingProcessor($db,$history,$sender,$logger,0);
$faker=Faker\Factory::create('en_US'); $faker->seed(2026);
$seeder=new CustomerSeeder($db,$faker); $seeder->seed();
check((int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn()===10000,'10,000 fictional customers');
check((int)$db->query('SELECT SUM(marketing_opt_in=1) FROM customers')->fetchColumn()===8000,'8,000 opted in and 2,000 opted out');
$sample=$db->query("SELECT * FROM customers WHERE customer_id='CUST-00001'")->fetch();
$seeder->seed();
check($sample===$db->query("SELECT * FROM customers WHERE customer_id='CUST-00001'")->fetch(),'repeat generation preserves existing values');
check((int)$db->query('SELECT COUNT(*) FROM customers')->fetchColumn()===10000,'repeat generation preserves total');
rejects(fn()=>$db->exec("UPDATE customers SET marketing_opt_in=2 WHERE customer_id='CUST-00001'"),'database rejects invalid consent');
rejects(fn()=>$db->exec("UPDATE customers SET email='customer00002@example.com' WHERE customer_id='CUST-00001'"),'database rejects duplicate email');
$preparer=new CampaignPreparer($db); check($preparer->prepare('TEST-BASE','PROMO15')===50,'prepare exactly 50 jobs');
$before=(new LocalDatabaseTools($db))->inspect('TEST-BASE');
check($before['distinct_recipients']===50&&$before['ineligible_recipients']===0,'selection contains 50 distinct opted-in customers');
check($preparer->prepare('TEST-BASE','PROMO15')===0,'repeat preparation creates zero new jobs');
check($before===(new LocalDatabaseTools($db))->inspect('TEST-BASE'),'repeat preparation preserves IDs and promo code');
rejects(fn()=>$preparer->prepare('TEST-BASE','CHANGED'),'changing promo code is rejected');
$partial=$db->prepare("INSERT INTO marketing_outbox (request_id,campaign_id,customer_id,promo_code,subject,body,created_at) VALUES (?,'TEST-PARTIAL','CUST-00001','PROMO15','test','test',UTC_TIMESTAMP())");
$partial->execute([bin2hex(random_bytes(16))]);
rejects(fn()=>$preparer->prepare('TEST-PARTIAL','PROMO15'),'partial campaign is rejected without adding recipients');
rejects(fn()=>$db->exec("UPDATE marketing_outbox SET customer_id='MISSING' WHERE campaign_id='TEST-PARTIAL'"),'outbox enforces customer foreign key');
$responses=[];
for($i=0;$i<50;$i++) {$responses[]=function($command) use($db){
    $payload=json_decode($command['MessageBody'],true,512,JSON_THROW_ON_ERROR);
    check(array_keys($payload)===['requestId','campaignId','customerId','promoCode','subject','body'],'SQS payload has identifiers and campaign content only');
    return new Result(['MessageId'=>'mock-'.bin2hex(random_bytes(8))]);
};}
[$sqs,$mock]=mockClient($responses);
$publisher=new QueuePublisher($db,$sqs,'https://sqs.ap-southeast-2.amazonaws.com/123456789012/test',$logger);
check($publisher->publishPending('TEST-BASE')===50,'50 acknowledged sends become QUEUED');
check($publisher->publishPending('TEST-BASE')===0&&count($mock)===0,'repeat publication makes no SQS request');
[$badSqs]=mockClient([new RuntimeException('Simulated network failure')]);
rejects(fn()=>(new QueuePublisher($db,$badSqs,'https://sqs.ap-southeast-2.amazonaws.com/123456789012/test',$logger))->publishPending('TEST-PARTIAL'),'unacknowledged publish propagates error');
check($db->query("SELECT status FROM marketing_outbox WHERE campaign_id='TEST-PARTIAL'")->fetchColumn()==='PENDING','failed publish remains PENDING');

$url='https://sqs.ap-southeast-2.amazonaws.com/123456789012/test';
$first=job('TEST-SEND');
[$sqs]=mockClient([function($command) use($db){
    check(outcome($db,'TEST-SEND')['status']==='SENT','SENT exists before DeleteMessage');
    check($command['ReceiptHandle']==='fixture-current-receipt-handle','acknowledgment uses current ReceiptHandle');
    return new Result();
}]);
$worker=new QueueWorker($sqs,$url,$processor,$logger);
check($worker->handle(message($first)),'eligible request succeeds and is acknowledged');
check($sender->calls===1,'eligible request submits one message');
[$sqs]=mockClient([new Result()]);
check((new QueueWorker($sqs,$url,$processor,$logger))->handle(message($first))&&$sender->calls===1,'sequential duplicate is acknowledged without SMTP');
[$sqs]=mockClient([new Result(),new Result()]);
$worker=new QueueWorker($sqs,$url,$processor,$logger);
check($worker->handle(message(job('TEST-OPTOUT','CUST-08001'))),'opted-out request is acknowledged');
check(outcome($db,'TEST-OPTOUT')['reason']==='marketing_opt_out'&&$sender->calls===1,'opt-out is stored SKIPPED without SMTP');
check($worker->handle(message(job('TEST-UNKNOWN','CUST-UNKNOWN'))),'unknown customer is acknowledged');
check(outcome($db,'TEST-UNKNOWN')['status']==='SKIPPED'&&$sender->calls===1,'unknown customer can be stored without history foreign-key failure');

$sender->fail=true;
[$sqs,$mock]=mockClient([]);
$retryJob=job('TEST-RETRY');
check(!(new QueueWorker($sqs,$url,$processor,$logger))->handle(message($retryJob)),'SMTP failure leaves message unacknowledged');
check(outcome($db,'TEST-RETRY')['status']==='FAILED','SMTP failure stores FAILED');
$sender->fail=false;
[$sqs]=mockClient([new Result()]);
check((new QueueWorker($sqs,$url,$processor,$logger))->handle(message($retryJob)),'FAILED outcome permits retry');
check(outcome($db,'TEST-RETRY')['status']==='SENT','successful retry replaces latest FAILED outcome');

[$sqs,$mock]=mockClient([]);
$worker=new QueueWorker($sqs,$url,$processor,$logger);
check(!$worker->handle(['MessageId'=>'bad-json','ReceiptHandle'=>'unused','Body'=>'{broken']),'invalid JSON is not acknowledged');
check(!$worker->handle(message(['campaignId'=>'BROKEN','subject'=>'Test','body'=>'Missing IDs'])),'missing fields are not acknowledged');
check(!$worker->handle(message(job('TEST-INJECT'))),'acknowledgment failure is reported separately from processing');
// The valid fixture tries DeleteMessage with an empty mock; its SENT record remains terminal.
check(outcome($db,'TEST-INJECT')['status']==='SENT','ack failure preserves terminal outcome');
$calls=$sender->calls;
[$sqs]=mockClient([new Result()]);
check((new QueueWorker($sqs,$url,$processor,$logger))->handle(message(job('TEST-INJECT')))&&$sender->calls===$calls,'redelivery after ack failure suppresses another SMTP send');
$events=array_map(fn($line)=>json_decode($line,true,512,JSON_THROW_ON_ERROR),file($log,FILE_IGNORE_NEW_LINES));
$names=array_column($events,'event');
check(in_array('ack_failed',$names,true)&&in_array('duplicate_skipped',$names,true)&&in_array('processing_failed',$names,true),'operational events distinguish failure, acknowledgment and duplicates');
$privateKeys=['body','subject','email','password','full_name','recipient_email'];
check(count(array_filter($events,fn($event)=>array_intersect_key($event,array_flip($privateKeys))!==[]))===0,'operational log excludes names, addresses, content and credentials');
echo "\n$passed checks passed. SMTP and SQS were mocked; no external email or queue operation occurred.\n";
