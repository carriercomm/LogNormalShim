<?php

/**
 * Scrapes from LogNormal the intraday summary data for the day indicated by the given timestamp,
 * then sends the data to graphite.
 */

require __DIR__ .'/../lib/LogNormalShimClient.php';

date_default_timezone_set('UTC');
$now = time();

$options = getopt('d:a:u:p:', array('date:', 'api_url:', 'graphite_url:', 'port:'));
if (isset($options['date'])) {
    $date = strtotime($options['date']);
    if ($date === false) {
        throw new Exception('Could not parse date');
    }
} else {
    $date = $now;
}

// We want to report daily data at 12:00:00 on the day in question
$seconds_per_day = 60 * 60 * 24;
$date = $date - ($date % $seconds_per_day) + ($seconds_per_day / 2);

if (isset($options['api_url'])) {
    $api_url = $options['api_url'];
} else {
    throw new Exception('api_url is required');
}

if (isset($options['graphite_url'])) {
    $graphite_url = $options['graphite_url'];
} else {
    throw new Exception('graphite_url is required');
}
if (isset($options['port'])) {
    $port = $options['port'];
} else {
    throw new Exception('port is required');
}

$ln = new LogNormalShimClient($api_url);
$graph = $ln->config['graphite']['namespace'] . '.daily'; //@todo accessor

// yo lognormal, give me some data.
$data = $ln->getSummary($date);

// yo graphite, take a look at this.
foreach ($data as $field => $value) {
    exec("echo $graph.$field $value $date | nc $graphite_url $port\n");
}
