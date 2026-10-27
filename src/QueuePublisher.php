<?php
declare(strict_types=1);
namespace Lab;
use Aws\Sqs\SqsClient;
use PDO;

final class QueuePublisher
{
    public function __construct(private PDO $db, private SqsClient $sqs,
        private string $url, private ActivityLogger $logger) {}

    public static function payload(array $row): array
    {
        return ['requestId'=>$row['request_id'],'campaignId'=>$row['campaign_id'],
            'customerId'=>$row['customer_id'],'promoCode'=>$row['promo_code'],
            'subject'=>$row['subject'],'body'=>$row['body']];
    }

    public function publishPending(string $campaign): int
    {
        $select = $this->db->prepare("SELECT * FROM marketing_outbox WHERE status='PENDING' AND campaign_id=? ORDER BY customer_id");
        $select->execute([$campaign]);
        $update = $this->db->prepare("UPDATE marketing_outbox SET status='QUEUED',queued_at=?,sqs_message_id=? WHERE request_id=? AND status='PENDING'");
        $count = 0;
        foreach ($select->fetchAll() as $row) {
            $job = self::payload($row);
            $phase = 'send';
            $messageId = null;
            try {
                $response = $this->sqs->sendMessage(['QueueUrl'=>$this->url,'MessageBody'=>json_encode($job,JSON_THROW_ON_ERROR)]); // Q1
                $messageId = $response['MessageId'] ?? null;
                if (!$messageId) { throw new \RuntimeException('SQS acknowledgment is missing MessageId'); }
                $phase = 'outbox_update';
                $update->execute([gmdate('Y-m-d H:i:s'),$messageId,$row['request_id']]); // Q2
                $phase = 'log';
                $this->logger->record('queued',ActivityLogger::ids($job)+['messageId'=>$messageId,'phase'=>'publish']); // M1
                $count++;
            } catch (\Throwable $e) {
                $this->logger->record('enqueue_failed',ActivityLogger::ids($job)+[
                    'messageId'=>$messageId,'phase'=>$phase,'errorType'=>get_class($e)]);
                throw $e; // Stop for investigation of possibly accepted sends.
            }
        }
        return $count;
    }
}
