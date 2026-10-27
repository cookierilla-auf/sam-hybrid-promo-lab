<?php
require __DIR__.'/config.php';
echo json_encode((new Lab\LocalDatabaseTools((new Lab\Database())->connect()))->inspect($argv[1]??'CAMP-REBUILD-20261027'),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
