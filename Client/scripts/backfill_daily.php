<?php

/**
 * Backfills lognormal summary data from the specified start time until the current time - 24 hours (we do
 * not scrape for the current day because the LogNormal data is incomplete)
 *
 * Usage: php backfill_daily.php --from <start>
 *   where start is a string specifying the start date (ex. "yesterday", "-1week")
 */

$options = getopt('f:', array('from:'));
if (!isset($options['from'])) {
    throw new Exception("--from is required");
}

date_default_timezone_set('UTC');
$start = strtotime($options['from']);
$end   = strtotime("yesterday");

if ($start == false) {
    throw new Exception('Could not parse start date');
} else if ($start > $end) {
    throw new Exception('Start date is invalid; please choose a start date before yesterday.');
}

$start = getNoonTimestamp($start);
$end = getNoonTimestamp($end);

require_once __DIR__.'/../lib/LogNormalShimClient.php';
$ln = new LogNormalShimClient();
$api_url        = $ln->config['api_url'];
$graphite_url   = $ln->config['graphite']['url'];
$graphite_port  = $ln->config['graphite']['port'];

for($date = $start; $date <= $end; $date = strtotime("+1 day", $date)) {
    exec("php " . __DIR__ . "/scrape_daily.php " .
         "--date \"@$date\" " .
         "--api_url $api_url " .
         "--graphite_url $graphite_url " .
         "--port $graphite_port "
     );
}

/**
 * Given a timestamp, return the timestamp corresponding to 12:00:00 on the same day
 */
function getNoonTimestamp($timestamp) {
    $seconds_per_day = 60 * 60 * 24;
    return $timestamp - ($timestamp % $seconds_per_day) + ($seconds_per_day / 2);
}
