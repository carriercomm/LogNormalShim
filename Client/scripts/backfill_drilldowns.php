<?php

/**
 * Backfills lognormal summary data for a set of drilldowns from the specified start time
 * until the current time
 *
 * Usage: php backfill_daily_drilldowns.php --from <start>
 *   where start is a string specifying the start date (ex. "yesterday", "-1week")
 */

$options = getopt('f:', array('from:'));
if (!isset($options['from'])) {
    throw new Exception("--from is required");
}

date_default_timezone_set('UTC');
$start =    strtotime($options['from']);
$end   =    strtotime("now");

if ($start == false) {
    throw new Exception('Could not parse start date');
} else if ($start > $end) {
    throw new Exception('Start date is invalid; please choose a start date before yesterday.');
}

require_once __DIR__.'/../lib/LogNormalShimClient.php';
$ln = new LogNormalShimClient();
$api_url        = $ln->config['api_url'];
$graphite_url   = $ln->config['graphite']['url'];
$graphite_port  = $ln->config['graphite']['port'];

for($date = $start; $date <= $end; $date = strtotime("+1 day", $date)) {
    echo("Starting to scrape for $date...");
    exec("php " . __DIR__ . "/scrape_drilldowns.php " .
         "--date \"@$date\" " .
         "--api_url $api_url " .
         "--graphite_url $graphite_url" .
         "--port $graphite_port ");
    echo(" done.\n");
}
