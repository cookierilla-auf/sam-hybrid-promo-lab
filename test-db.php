<?php
require __DIR__.'/config.php';
$db=(new Lab\Database())->connect();
echo "Local database connection successful.\n";
echo 'Server: '.$db->query('SELECT VERSION()')->fetchColumn()."\n";
foreach ($db->query('SHOW TABLES') as $row) { echo reset($row)."\n"; }
