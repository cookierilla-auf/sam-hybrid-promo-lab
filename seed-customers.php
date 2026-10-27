<?php
require __DIR__.'/config.php';
$db=(new Lab\Database())->connect();
$faker=Faker\Factory::create('en_US');
$faker->seed(2026);
(new Lab\CustomerSeeder($db,$faker))->seed();
echo "Customer generation complete; existing records preserved.\n";
echo json_encode((new Lab\LocalDatabaseTools($db))->inspect($argv[1]??'CAMP-REBUILD-20261027'),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
