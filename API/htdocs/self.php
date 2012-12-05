<?php

date_default_timezone_set('UTC');
require __DIR__ .'/../../Client/lib/LogNormalShimClient.php';

$ln = new LogNormalShimClient();
$data = $ln->getIntradayRange(strtotime('-10 minutes'), time());
if($ln->error) {
    echo $ln->errmessage;
    exit;
}

echo '<pre>';
var_export($data);
echo '</pre>';
