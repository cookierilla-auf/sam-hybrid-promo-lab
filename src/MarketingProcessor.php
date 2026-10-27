<?php
declare(strict_types=1);
namespace Lab;
use PDO;

final class MarketingProcessor
{
    public function __construct(private PDO $db, private EmailHistory $history, private MailGateway $sender,
        private ActivityLogger $logger, private int $delaySeconds = 11) {}

    public static function validate(array $job): void
    {
        foreach (['requestId'=>100,'campaignId'=>64,'customerId'=>32,'promoCode'=>64,'subject'=>255,'body'=>65535] as $key=>$limit) {
            if (!isset($job[$key]) || !is_string($job[$key]) || trim($job[$key])==='' || mb_strlen($job[$key])>$limit) {
                throw new \InvalidArgumentException('Invalid or missing field: '.$key);
            }
        }
        if (strlen($job['body'])>65535 || preg_match('/[\r\n]/',$job['subject'])) {
            throw new \InvalidArgumentException('Body exceeds storage limit or subject contains a newline');
        }
    }

    public function process(array $job, array $context = []): void
    {
        self::validate($job);
        $ids = ActivityLogger::ids($job) + array_intersect_key($context,array_flip(['messageId']));
        if ($this->history->isComplete($job['campaignId'],$job['customerId'])) { // P1
            $this->logger->record('duplicate_skipped',$ids+['phase'=>'process']);
            return;
        }
        $this->logger->record('processing_started',$ids+['phase'=>'process']);
        try {
            $lookup = $this->db->prepare('SELECT customer_id,full_name,email,marketing_opt_in FROM customers WHERE customer_id=?'); // P2
            $lookup->execute([$job['customerId']]);
            $customer = $lookup->fetch();
            if (!$customer || (int)$customer['marketing_opt_in']!==1) { // P3
                $reason = $customer ? 'marketing_opt_out' : 'unknown_customer';
                $this->history->save($job,'SKIPPED',['reason'=>$reason]);
                $this->logger->record('skipped',$ids+['phase'=>'process','reason'=>$reason]); // M2
                return;
            }
            $messageId = $this->sender->send($customer,$job); // P4; no open DB transaction during SMTP.
            $this->history->save($job,'SENT',['recipient_email'=>$customer['email'],
                'provider'=>'Mailtrap Sandbox','message_id'=>$messageId,'sent_at'=>gmdate('Y-m-d H:i:s')]); // P5
            $this->logger->record('sent',$ids+['phase'=>'process']); // M2: after storage.
            if ($this->delaySeconds>0) { sleep($this->delaySeconds); }
        } catch (\Throwable $e) {
            // A terminal record must survive a later logging failure.
            if (!$this->history->isComplete($job['campaignId'],$job['customerId'])) {
                $this->history->save($job,'FAILED',['reason'=>'processing_exception: '.get_class($e)]);
            }
            throw $e;
        }
    }
}
