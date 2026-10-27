<?php
declare(strict_types=1);
namespace Lab;
use PDO;

final class LocalDatabaseTools
{
    public function __construct(private PDO $db) {}

    public function initialize(string $schema): void
    {
        $sql = file_get_contents($schema);
        if ($sql === false) { throw new \RuntimeException('Cannot read schema.sql'); }
        $this->db->exec($sql);
    }

    public function inspect(string $campaign): array
    {
        $selection = $this->db->prepare('SELECT request_id, customer_id, promo_code, status FROM marketing_outbox WHERE campaign_id=? ORDER BY customer_id');
        $selection->execute([$campaign]);
        $rows = $selection->fetchAll();
        $history = $this->db->prepare('SELECT status,COUNT(*) AS total FROM sent_marketing_emails WHERE campaign_id=? GROUP BY status');
        $history->execute([$campaign]);
        $eligible = $this->db->prepare('SELECT COUNT(*) FROM marketing_outbox o JOIN customers c ON o.customer_id=c.customer_id WHERE o.campaign_id=? AND c.marketing_opt_in<>1');
        $eligible->execute([$campaign]);
        return [
            'customers' => (int) $this->db->query('SELECT COUNT(*) FROM customers')->fetchColumn(),
            'sample_customers' => $this->db->query('SELECT customer_id,full_name,email,marketing_opt_in FROM customers ORDER BY customer_id LIMIT 5')->fetchAll(),
            'consent' => $this->db->query('SELECT marketing_opt_in,COUNT(*) AS total FROM customers GROUP BY marketing_opt_in')->fetchAll(),
            'campaign' => $campaign,
            'requests' => count($rows),
            'distinct_recipients' => count(array_unique(array_column($rows, 'customer_id'))),
            'ineligible_recipients' => (int) $eligible->fetchColumn(),
            'selection_fingerprint' => hash('sha256', json_encode(array_map(
                fn($r) => [$r['request_id'],$r['customer_id'],$r['promo_code']], $rows), JSON_THROW_ON_ERROR)),
            'publication' => array_count_values(array_column($rows, 'status')),
            'outcomes' => $history->fetchAll(),
        ];
    }
}
