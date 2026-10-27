<?php
declare(strict_types=1);
namespace Lab;
use Aws\Sqs\SqsClient;

final class QueueWorker
{
    public function __construct(private SqsClient $sqs, private string $url,
        private MarketingProcessor $processor, private ActivityLogger $logger) {}

    public function handle(array $message): bool
    {
        $phase = 'decode';
        $ids = ['messageId'=>$message['MessageId']??null];
        try {
            $this->logger->record('received',$ids+['phase'=>'receive',
                'receiveCount'=>(int)($message['Attributes']['ApproximateReceiveCount']??1)]);
            $job = json_decode($message['Body'],true,512,JSON_THROW_ON_ERROR);
            if (!is_array($job) || array_is_list($job)) { throw new \InvalidArgumentException('Expected a JSON object'); }
            MarketingProcessor::validate($job);
            $ids += ActivityLogger::ids($job);
            $phase = 'process';
            $this->processor->process($job,$ids); // W1
            $phase = 'delete'; // M3
            $this->sqs->deleteMessage(['QueueUrl'=>$this->url,'ReceiptHandle'=>$message['ReceiptHandle']]); // W2
            $phase = 'ack_log';
            $this->logger->record('deleted',$ids+['phase'=>'delete']); // M3: after successful acknowledgment.
            return true;
        } catch (\Throwable $e) {
            $event = $phase==='delete' ? 'ack_failed' : ($phase==='ack_log' ? 'ack_log_failed' : 'processing_failed');
            $this->logger->record($event,$ids+['phase'=>$phase,'errorType'=>get_class($e)]);
            return false;
        }
    }

    public function run(int $maxMessages = 0, int $maxSeconds = 0): void
    {
        $started = time();
        $handled = 0;
        while (($maxMessages===0 || $handled<$maxMessages) && ($maxSeconds===0 || time()-$started<$maxSeconds)) {
            try {
                $response = $this->sqs->receiveMessage(['QueueUrl'=>$this->url,
                    'MaxNumberOfMessages'=>1,'WaitTimeSeconds'=>20,
                    'MessageSystemAttributeNames'=>['ApproximateReceiveCount','SentTimestamp']]);
            } catch (\Throwable $e) {
                $this->logger->record('receive_failed',['phase'=>'receive','errorType'=>get_class($e)]);
                throw $e;
            }
            foreach ($response['Messages']??[] as $message) {
                $this->handle($message);
                $handled++;
            }
        }
    }
}
