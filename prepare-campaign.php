<?php
require __DIR__.'/config.php';
if ($argc!==3) { fwrite(STDERR,"Usage: php prepare-campaign.php CAMPAIGN PROMO_CODE\n"); exit(1); }
$db=(new Lab\Database())->connect();
$count=(new Lab\CampaignPreparer($db))->prepare($argv[1],$argv[2]);
echo $count ? "Prepared $count PENDING requests.\n" : "Original 50 recipients, request IDs and promo code preserved.\n";
echo json_encode((new Lab\LocalDatabaseTools($db))->inspect($argv[1]),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
