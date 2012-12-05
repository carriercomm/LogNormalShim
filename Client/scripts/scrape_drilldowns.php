<?php

require __DIR__ .'/../lib/LogNormalShimClient.php';

/**
 * Scrapes from LogNormal the intraday summary data for the day indicated by the given timestamp,
 * then sends the data to graphite.
 */

// Setup, check options
date_default_timezone_set('UTC');
$options = getopt('d:a:u:p:', array('date:', 'api_url:', 'graphite_url:', 'port:'));
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

$now = time();
$script_start = $now;

$file = 'sd_out.log';
$fh = fopen($file, 'w') or die("can't open file");
fwrite($fh, "Script started at " . $script_start . "\n");

$options = getopt('d:a:u:p:', array('date:', 'api_url:', 'graphite_url:', 'port:'));
if (isset($options['date'])) {
    $date = strtotime($options['date']);
    if ($date === false) {
        throw new Exception('Could not parse date');
    }
} else {
    $date = $now;
}

// We want to report daily data at 00:00:00 on the day in question
$seconds_per_day = 60 * 60 * 24;
$date = $date - ($date % $seconds_per_day);

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

// Let's do page (one-level) drilldowns before we do page/bandwidth, page/country, etc.
// (cannot just sum up daily.pages.<page>.bandwidth.*.load or something similar because we're
// dealing with medians)
$scrape_start = time();
fwrite($fh, "Starting to scrape for 'pages' at " . $scrape_start . "....");
$pages = $ln->getSummaryByPageGroup($date);
$scrape_end = time();
fwrite($fh, "took " . ($scrape_end - $scrape_start) . " seconds\n");
$report_start = time();
fwrite($fh, "Starting to report to graphite at " . $report_start . "\n\n");

foreach ($pages as $page => $summary) {
    $summary_data = $summary[0];
    foreach ($summary_data as $summary_field => $value) {
        $report = "$graph.pages.$page.$summary_field $value $date";
        $cmd = "echo \"$report\" | nc $graphite_url $port";
        fwrite($fh, "$cmd\n");
        exec("$cmd");
    }
}
$report_end = time();
fwrite($fh, "took " . ($report_end - $report_start) . " seconds\n");

// yo lognormal, give me some data.
$scrape_start = time();
fwrite($fh, "Starting to scrape for 'browsers' at " . $scrape_start . " ...");
$data['browsers'] = $ln->getSummaryByPageGroupThenBrowser($date);
$scrape_end = time();
fwrite($fh, "took " . ($scrape_end - $scrape_start) . " seconds\n");

$scrape_start = time();
fwrite($fh, "Starting to scrape for 'countries' at " . $scrape_start . " ...");
$data['countries'] = $ln->getSummaryByPageGroupThenCountry($date);
$scrape_end = time();
fwrite($fh, "took " . ($scrape_end - $scrape_start) . " seconds\n");

$scrape_start = time();
fwrite($fh, "Starting to scrape for 'bandwidth' at " . $scrape_start . " ...");
$data['bandwidth'] = $ln->getSummaryByPageGroupThenBandwidth($date);
$scrape_end = time();
fwrite($fh, "took " . ($scrape_end - $scrape_start) . " seconds\n");

// yo graphite, take a look at this.
$report_start = time();
fwrite($fh, "Starting to report to graphite at " . $report_start . "\n\n");
foreach (array_keys($data) as $drilldown) {
    foreach ($data[$drilldown] as $page => $d) {
        // $d of the form ('Chrome' => (array ('moe' => .1, 'load' => ...)), 'Firefox' => ...) or somesuch
        // OR of the form ('Chrome => array('code' => <code>, 'message' => <message>))
        foreach ($d as $key => $summary_data) {

            // Error check
            if (array_key_exists('code', $summary_data) && array_key_exists('message', $summary_data)) {
                fwrite(STDERR,
                    "API returned error code " . $summary_data['code'] . " attempting to fetch summary data " . 
                    "pages.$page.$drilldown.$key: " . $summary_data['message']
                );
                continue;
            }

            foreach ($summary_data as $summary_field => $value) {
                $report = "$graph.pages.$page.$drilldown.$key.$summary_field $value $date";
                $cmd = "echo \"$report\" | nc $graphite_url $port";
                fwrite($fh, "$cmd\n");
                exec("$cmd");
            }
        }
    }
}
$report_end = time();
fwrite($fh, "took " . ($report_end - $report_start) . " seconds\n");


$script_end = time();
fwrite($fh,"Script completed at " . $script_end . " ... took " . ($script_end - $script_start) . " seconds\n");
fclose($fh);
