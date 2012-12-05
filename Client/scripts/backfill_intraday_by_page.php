<?php

/**
 * Backfills lognormal intraday data from specified start time until current time
 *
 * Usage: php backfill_intraday.php --from <start>
 *   where start is a string specifying the start date (ex. "yesterday", "-1week")
 */

$options = getopt('f:t::', array('from:', 'to::'));
if (!isset($options['from'])) {
    throw new Exception("--from is required");
}
if (!isset($options['to'])) {
    $options['to'] = 'now';
}

date_default_timezone_set('UTC');
$start = strtotime($options['from']);
$end   = strtotime($options['to']);

if ($start == false) {
    throw new Exception('Could not parse start date');
}

require_once __DIR__.'/../lib/LogNormalShimClient.php';
$ln = new LogNormalShimClient();
$api_url       = $ln->config['api_url'];
$graphite_url  = $ln->config['graphite']['url'];
$graphite_port = $ln->config['graphite']['port'];

exec("php " . __DIR__ . "/scrape_intraday_by_page.php " .
     "--api_url $api_url " .
     "--graphite_url $graphite_url " .
     "--port $graphite_port " .
     "--range_start \"@$start\" --range_end \"@$end\" > out");
