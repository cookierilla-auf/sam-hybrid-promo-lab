<?php
require __DIR__.'/config.php';
(new Lab\LocalDatabaseTools((new Lab\Database())->connect()))->initialize(__DIR__.'/schema.sql');
echo "All three local tables are initialized.\n";
