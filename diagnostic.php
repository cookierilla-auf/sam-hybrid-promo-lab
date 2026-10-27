<?php
require __DIR__.'/config.php';
$mode=$argv[1]??'';
$db=(new Lab\Database())->connect();
if ($mode==='duplicate') {
    $find=$db->prepare("SELECT o.* FROM marketing_outbox o JOIN sent_marketing_emails h ON o.campaign_id=h.campaign_id AND o.customer_id=h.customer_id WHERE o.campaign_id=? AND h.status='SENT' ORDER BY o.customer_id LIMIT 1");
    $find->execute([$argv[2]??'CAMP-REBUILD-20261027']);
    $row=$find->fetch();
    if (!$row) { throw new RuntimeException('No genuinely completed local pair is available for the duplicate test'); }
    $job=Lab\QueuePublisher::payload($row);
} elseif ($mode==='recovery') {
    $job=['requestId'=>bin2hex(random_bytes(16)),'campaignId'=>$argv[2]??'CAMP-RECOVERY-20261027',
        'customerId'=>'CUST-08001','promoCode'=>'PROMO15','subject'=>'Recovery diagnostic','body'=>'Verify consent handling without an SMTP submission.'];
} elseif ($mode==='broken') {
    $job=['campaignId'=>'CAMP-BROKEN-20261027','subject'=>'Test','body'=>'Missing IDs'];
} else {
    fwrite(STDERR,"Usage: php diagnostic.php duplicate [CAMPAIGN] | recovery | broken\n"); exit(1);
}
$result=Lab\Settings::queue()->sendMessage(['QueueUrl'=>Lab\Settings::required('SQS_QUEUE_URL'),
    'MessageBody'=>json_encode($job,JSON_THROW_ON_ERROR)]);
echo json_encode(['mode'=>$mode,'campaignId'=>$job['campaignId'],'requestId'=>$job['requestId']??null,
    'customerId'=>$job['customerId']??null,'messageId'=>$result['MessageId']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
