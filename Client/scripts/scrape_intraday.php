<?php

/**
 * Scrapes intraday data for the given minute from LogNormal and sends the data to graphite.
 *
 * --range_start (optional)     The start of the range whose data to collect; can only
 *                              be used in conjunction with range_end
 * --range_end (optional)       The end of the range whose data to collect; can only
 *                              be used in conjunction with range_start
 * --timestamp (optional)       The timestamp whose data to collect; may not be used in conjunction
 *                              with range_start/end or day
 * --day (optional)             The date whose data to collect; may not be used in conjunction
 *                              with range_start/end or timestamp
 *
 * --api_url (required)         The URL of the LogNormal API
 * --graphite_url (required)    The URL of the graphite server
 * --port (required)            The port on which the graphtie server is listening
 */

require __DIR__ .'/../lib/LogNormalShimClient.php';

date_default_timezone_set('UTC');
$now = time();

$options = getopt(
    'S:E:t:d:a:u:p:', array(
        'range_start:', 'range_end:', 'timestamp:', 'day:',
        'api_url:', 'graphite_url:', 'port:'
    )
);

foreach (array('api_url', 'graphite_url', 'port') as $opt) {
    if (!(isset($options[$opt]))) {
        throw new Exception("$opt is required");
    }
}
$graphite_url = $options['graphite_url'];
$port = $options['port'];

// Get information about the date(s) that we should be scraping
if (isset($options['range_start'])) {
    $mode = 'RANGE';
    if (!(isset($options['range_end']))) {
        throw new Exception("range_end is required with range_start");
    }
    $range_start = strtotime($options['range_start']);
    $range_end   = strtotime($options['range_end']);
    if ($range_start === false) {
        throw new Exception("Could not parse range_start");
    } else if ($range_end === false) {
        throw new Exception("Could not parse range_end");
    }

    foreach (array('timestamp', 'day') as $opt) {
        if (isset($options[$opt])) {
            throw new Exception("$opt cannot be used with range_start and range_end");
        }
    }
} else if (isset($options['range_end'])) {
    throw new Exception("range_start is required with range_end");
} else if (isset($options['timestamp'])) {
    $mode = 'TIMESTAMP';
    if (isset($options['day'])) {
        throw new Exception("day cannot be used with timestamp");
    }
    $timestamp = strtotime($options['timestamp']);
    if ($timestamp === false) {
        throw new Exception("Could not parse timestamp");
    }

    // Round timestamp to the nearest minute so that the timestamp matches a time at which
    // LogNormal data is recorded
    $timestamp -= ($timestamp % 60);
} else if (isset($options['day'])) {
    $mode = 'DAY';
    $day = strtotime($options['day']);
    if ($day === false) {
        throw new Exception("Could not parse day");
    }
} else {
    $mode = 'MOST_RECENT';
}


$ln = new LogNormalShimClient($options['api_url']);

$report = array();
if ($mode == 'RANGE') {
    // yo lognormal, give me some data.
    $data = $ln->getIntradayRange($range_start, $range_end);

    // Get info to report
    $report = $data;
} else if ($mode == 'TIMESTAMP') {
    // yo lognormal, give me some data.
    $data = $ln->getIntraday($timestamp);

    // Get info to report
    echo $timestamp . "\n";
    if (array_key_exists($timestamp, $data)) {
        $report = array($timestamp => $data[$timestamp]);
    } else {
        fwrite(STDERR, "Data for " . $timestamp . " is not (yet?) available from LogNormal\n");
    }
} else if ($mode == 'DAY') {
    $seconds_in_day = 60 * 60 * 24;
    $day_start = $day - ($day % $seconds_in_day);
    $day_end = $day_start + $seconds_in_day - 1;

    // yo lognormal, give me some data.
    $data = $ln->getIntradayRange($day_start, $day_end);

    // Get info to report
    $report = $data;
} else if ($mode == 'MOST_RECENT') {
    // yo lognormal, give me some data.
    $data = $ln->getIntraday($now);

    // Get info to report
    $timestamps = array_keys($data);
    $most_recent = $timestamps[count($timestamps) - 1];
    $report = array($most_recent => $data[$most_recent]);
}

$graph = $ln->config['graphite']['namespace'] . '.intraday'; //@todo accessor

// yo graphite, take a look at this.
foreach (array_keys($report) as $moment) {
    foreach ($report[$moment] as $field => $value) {
        exec("echo $graph.$field $value $moment | nc $graphite_url $port\n");
    }
}
