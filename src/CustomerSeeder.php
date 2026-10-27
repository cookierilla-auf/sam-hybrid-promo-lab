<?php
declare(strict_types=1);
namespace Lab;
use PDO;
use Faker\Generator;

final class CustomerSeeder
{
    public function __construct(private PDO $db, private Generator $faker) {}

    public function seed(): void
    {
        $insert = $this->db->prepare('INSERT INTO customers (customer_id,full_name,email,marketing_opt_in,created_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE customer_id=customer_id');
        $this->db->beginTransaction();
        try {
            for ($i = 1; $i <= 10000; $i++) {
                $name = mb_substr($this->faker->name(), 0, 150); // S1
                $consent = $i <= 8000 ? 1 : 0; // S2
                $insert->execute([sprintf('CUST-%05d',$i),$name,sprintf('customer%05d@example.com',$i),$consent,gmdate('Y-m-d H:i:s')]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
