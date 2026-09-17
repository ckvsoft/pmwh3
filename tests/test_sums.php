<?php

declare(strict_types=1);

use pmwh3\Utils\SizeConverter;
use pmwh3\Utils\CustomerUtil;

// Autoload anpassen
require_once __DIR__ . '/../../../var/config.php';
require_once __DIR__ . '/../../../library/ckvsoft/autoload.php';
new ckvsoft\Autoload(__DIR__ . '/../../../library/ckvsoft');

require_once __DIR__ . '/../modulautoload.php';
require_once __DIR__ . '/../model/traffic_model.php';

// === Test Traffic_Model functions ===
$trafficModel = new Traffic_Model();
$customerId = 1;
$date = '';

// Get traffic by customer
$traffic = $trafficModel->getTrafficByCustomerId($customerId, $date);
echo "\nTraffic for Customer ID $customerId in $date:\n";
foreach ($traffic as $cid => $val) {
    $name = CustomerUtil::getCustomerNameById($cid);
    echo "Customer: $name (ID $cid)\n";
    echo "  Apache: " . SizeConverter::bytesToHumanReadable($val['apache']) . "\n";
    echo "  FTP: " . SizeConverter::bytesToHumanReadable($val['ftp']) . "\n";
    echo "  Mail: " . SizeConverter::bytesToHumanReadable($val['mail']) . "\n";
    echo "  Total: " . SizeConverter::bytesToHumanReadable($val['sum']) . "\n\n";
}

// Optional: Summen prüfen
$sumApache = 0;
$sumFTP = 0;
$sumMail = 0;
foreach ($traffic as $val) {
    $sumApache += $val['apache'];
    $sumFTP += $val['ftp'];
    $sumMail += $val['mail'];
}
$total = $sumApache + $sumFTP + $sumMail;

echo "Total across all customers:\n";
echo "Apache: " . SizeConverter::bytesToHumanReadable($sumApache) . "\n";
echo "FTP: " . SizeConverter::bytesToHumanReadable($sumFTP) . "\n";
echo "Mail: " . SizeConverter::bytesToHumanReadable($sumMail) . "\n";
echo "Overall: " . SizeConverter::bytesToHumanReadable($total) . "\n";
