<?php
require __DIR__.'/config.php';
$response=Lab\Settings::queue()->getQueueAttributes(['QueueUrl'=>Lab\Settings::required('SQS_QUEUE_URL'),
    'AttributeNames'=>['QueueArn','VisibilityTimeout','MessageRetentionPeriod','ReceiveMessageWaitTimeSeconds','RedrivePolicy','ApproximateNumberOfMessages','ApproximateNumberOfMessagesNotVisible']]);
echo json_encode($response['Attributes'],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
