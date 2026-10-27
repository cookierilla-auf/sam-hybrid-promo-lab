<?php
declare(strict_types=1);
namespace Lab;
use PDO;

final class CampaignPreparer
{
    public function __construct(private PDO $db) {}

    public function prepare(string $campaign, string $promo): int
    {
        if ($campaign === '' || mb_strlen($campaign)>64 || $promo === '' || mb_strlen($promo)>64) {
            throw new \InvalidArgumentException('Campaign and promo code must contain 1-64 characters');
        }
        $this->db->beginTransaction();
        try {
            $existing = $this->db->prepare('SELECT customer_id,promo_code FROM marketing_outbox WHERE campaign_id=?');
            $existing->execute([$campaign]);
            $rows = $existing->fetchAll(); // C3: preserve the original selection exactly.
            if ($rows) {
                if (count($rows)!==50 || count(array_unique(array_column($rows,'promo_code')))!==1 || $rows[0]['promo_code']!==$promo) {
                    throw new \RuntimeException('Existing selection is partial or has a different promo code; investigate before continuing');
                }
                $this->db->commit();
                return 0;
            }
            $recipients = $this->db->query('SELECT customer_id FROM customers WHERE marketing_opt_in=1 ORDER BY RAND() LIMIT 50')->fetchAll(); // C2
            if (count($recipients)!==50) { throw new \RuntimeException('At least 50 eligible customers are required'); }
            $insert = $this->db->prepare('INSERT INTO marketing_outbox (request_id,campaign_id,customer_id,promo_code,subject,body,created_at) VALUES (?,?,?,?,?,?,?)');
            foreach ($recipients as $recipient) {
                $insert->execute([bin2hex(random_bytes(16)),$campaign,$recipient['customer_id'],$promo,
                    'Laboratory promotion','You have been selected for our offer. Use promo code '.$promo.'.',gmdate('Y-m-d H:i:s')]); // C1
            }
            $this->db->commit();
            return 50;
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) { $this->db->rollBack(); }
            throw $e;
        }
    }
}
