<?php

require_once(__DIR__ . '/util/Normalize.php');

ini_set('max_execution_time', 60 * 30);       // Thirty minutes?! ..yeah.

/**
 * LogNormalShim
 * Contains functions that access LogNormal pages and return the data that they contain
 */
class LogNormalShim {

    /* DATETIME_FORMAT - the format for all DateTime objects stored as strings (ex. array keys) by
     * this class.  Also the date format used in LogNormal URL conventions. */
    const DATETIME_FORMAT = "Y/m/d";

    /**
     * Creates a new LogNormal object
     *
     * @param domain the domain whose LogNormal data will be accessed with this point
     * @param cookie a valid login cookie for that domain (@todo this needs to be more legit)
     */
    public function __construct($domain, $cookie) {
        $this->domain = $domain;
        $this->cookie = $cookie;

        $this->base_url = "https://app.lognormal.com/domain/$this->domain";
        $this->error = null;
    }

    /**
     * @param date a DateTime object
     * @param dataset_name the name of the dataset to retreive, ex. 'intraday'
     * @param values The keys to be included in the returned dataset
     * @param drilldown_specs an array specifying the desired drilldowns; should be of the form
     *      array ('pagegroup' => '<pagegroup>',
     *             'country'   => '<country>',
     *             'bandwidth' => '<bandwidth>',
     *             'browser'   => '<browser>')
     *
     * @return The contents of the requested dataset (for $values, if specified) on the given date
     *
     * examples: // Return the country data collected for CA, US, and GB yesterday
     *           getFromDataset(strtotime("yesterday"), 'country', array('CA', 'US', 'GB'))
     */
    public function getFromDataset($date, $dataset_name, $values = null, $drilldown_specs = null) {
        $datasets = $this->getDatasets($date, $drilldown_specs);
        if (!is_null($this->error)) {
            return $this->error;
        }
        if (!array_key_exists($dataset_name . "_data", $datasets)) {
            $this->registerError(ApiError::LOGNORMAL_NO_DATA, "The requested dataset $dataset_name could not be extracted from the LogNormal page");
            return $this->error;
        }
        $dataset = $datasets[$dataset_name . "_data"];
        if (!is_null($values)) {
            $dataset = array_intersect_key($dataset, array_flip($values));
        }

        return $dataset;
    }

    /**
     * @param date a DateTime object
     * @param dataset_name the name of the dataset to retreive, ex. 'intraday'
     * @param drilldown_specs an array specifying the desired drilldowns; should be of the form
     *      array ('pagegroup' => '<pagegroup>',
     *             'country'   => '<country>',
     *             'bandwidth' => '<bandwidth>',
     *             'browser'   => '<browser>')
     *
     * @return The requested dataset from the given date
     */
    public function getDataset($date, $dataset_name, $drilldown_specs = null) {
        $data = $this->getFromDataset($date, $dataset_name, null, $drilldown_specs);
        if (!is_null($this->error)) {
            return $this->error;
        }
        return $data;
    }

    /**
     * Returns the data available on a lognormal page, in the form
     * array('summary' => <...>, 'intraday_data' => <...>, ...)
     *
     * @param date the date whose data to retreive
     * @param drilldown_specs an array specifying the desired drilldowns; should be of the form
     *      array ('pagegroup' => '<pagegroup>',
     *             'country'   => '<country>',
     *             'bandwidth' => '<bandwidth>',
     *             'browser'   => '<browser>')
     */
    public function getDatasets($date = null, $drilldown_specs = null) {
        $data = $this->extractLogNormalDataset($this->getLogNormalURL($date, $drilldown_specs));
        if (!is_null($this->error)) {
            return $this->error;
        }
        return $data;
    }

    /**
     * Gets the contents of the requested LogNormal page
     *
     * @returns the lines of the lognormal page requersted, in array form
     */
    private function curlForPage($lognormal_url) {
        // Let's not kill LogNormal (avoid 504's; @todo could be implemented in a much better fashion)
        sleep(1);

        // Set up cURL channel
        $channel = curl_init();
        curl_setopt($channel, CURLOPT_URL, $lognormal_url);
        curl_setopt($channel, CURLOPT_COOKIE, $this->cookie);

        // DO NOT use cache
        curl_setopt($channel, CURLOPT_FORBID_REUSE, 1);
        curl_setopt($channel, CURLOPT_FRESH_CONNECT, 1);

        // Perform cURL
        curl_setopt($channel, CURLOPT_RETURNTRANSFER, true);
        $lines = curl_exec($channel);

        if (curl_errno($channel)) {
            $this->registerError(
                ApiError::LOGNORMAL_CURL_ERROR,
                'API encountered cURL error while trying to access LogNormal: ' . curl_error($channel)
            );
            $lines = "";
        } else if (($http_error = curl_getinfo($channel, CURLINFO_HTTP_CODE)) >= 400) {
            $this->registerError(
                ApiError::LOGNORMAL_HTTP_ERROR,
                "API encountered HTTP error $http_error while trying to access LogNormal at $lognormal_url\n"
            );
        }

        curl_close($channel);

        return explode("\n", $lines);
    }

    /**
     *
     * Extracts any summary data that may be contained in the given line
     * @param line the line from which to extract the summary data
     * @param summary thearray in which to store the summary data
     */
    private function extractSummaryDataFromLine($line, $summary) {
        $load = null; $n5percentile = null; $n8percentile = null; $nsamples = null; $moe = null;
        // Pull out average median load time, p95, p98, number of samples taken (n), moe
        if (preg_match(
            '/span style="font-size: 4em; line-height: 1em;">([\d\.]*) sec/',
            $line,
            $matches
        )) {
            $summary['load'] = $matches[1];
        } else if(preg_match(
                '/<td>p9(\d):\s+([\d\.]*) sec/',
                $line,
                $matches
            ))
        {
            if ($matches[1] == 5) {
                $summary['perc95'] = $matches[2];
            } else if ($matches[1] == 8) {
                $summary['perc98'] = $matches[2];
            }
        } else if(preg_match('/<td>n:\s+([\d\.,]*)/', $line, $matches)) {
            $summary['n'] = str_replace(',', '', $matches[1]);
        } else if(preg_match('/<td>\s*&plusmn;\s*([\d\.]*)/', $line, $matches)) {
            $summary['moe'] = $matches[1];
        }

        return $summary;
    }

    /**
     * Extract the LogNormal data from an array of lines from a LogNormal page
     *
     * @param url the url for the page from which to extract data
     * @return the data from the page in the form
     *      array( 'intraday_data' => <intraday_data>, 'summary' => array('load => <load>, ...) )
     */
    private function extractLogNormalDataset($url) {
        $lines = $this->curlForPage($url);
        $dataset_name;
        $dataset = array();
        $in_block = false;
        $buffer;

        # Summary data
        $summary = array();
        foreach ($lines as $line_num => $line) {

            # If we've hit the beginning of a block...
            $regex_var_start = '/\s*(?:var |window\.)([\w\_]+)\s*=\s*\{/';
            if (!$in_block && preg_match($regex_var_start, $line, $matches)) {

                # Grab the name of the variable from the declaration
                $dataset_name = $matches[1];

                # Remove the declaration portion of this line of the object
                $line = preg_replace($regex_var_start, '{', $line);

                $buffer = "";
                $in_block = true;
            } else {
                // Maybe this line contains summary data
                $summary = $this->extractSummaryDataFromLine($line, $summary);
            }
            if ($in_block) {
                # If we hit a single semicolon, we've reached the end of the data structure;
                # otherwise, we're still parsing
                if (preg_match('/^\s*;\s*$/', $line)) {
                    $in_block = false;

                    # Strip out the stray comma from the previous line from the buffer
                    $buffer = preg_replace('/,\]/', "]", $buffer);
                    $dataset[$dataset_name] = json_decode($buffer);

                } else {
                    # Turn '' values into words
                    $line = preg_replace('/\'\'/', "NOVAL", $line);

                    # Take care of special (accented) characters
                    $line = normalize($line);

                    # Clean quoted values (get rid of periods, commas)
                    $line = preg_replace(
                        '/v:\s*"([\w\s,\.]*)"/e',
                        'strtr("v: \"$1\"", ",", " ")',
                        $line
                    );

                    # Strip whitespace, single quotes, and double quotes
                    $line = preg_replace('/[\s\']*/', "", $line);
                    $line = str_replace('"', '', $line);

                    # Convert javascript date object declarations into a Unix timestamp
                    # (recall that we just stripped whitespace above, hence 'newDate')
                    $line = preg_replace(
                        '/newDate\((\d+),(\d+),(\d+),(\d+),(\d+)\)/e',
                        "LogNormalShim::getTimestampFromLogNormalDate('$1','$2','$3','$4', '$5')",
                        $line
                    );

                    # Add quotation marks
                    $line = preg_replace('/([\w\-\.\(\)\/]+)/', '"$1"', $line);

                    # Strip newlines
                    # $line = str_replace("\n", "", $line);

                    # Append this line to the buffer
                    $buffer .= $line;
                }
            }
        }

        $ln_data = $this->getLogNormalDataFromJSONObject($dataset, $summary);

        $data = false;
        foreach($ln_data as $ds) {
            if (!empty($ds)) {
                $data = true;
            }
        }

        if (!$data) {
            $this->registerError(
                ApiError::LOGNORMAL_NO_DATA,
                "Unable to extract any data from the LogNormal page at $url"
            );
        }

        return $ln_data;
    }


    /**
     * Given a PHP object decoded from a LogNormal JSON object, put it into a more useable format.
     *
     * The returned object will be an associative array with a key for each set of data scraped from
     * LogNormal (ex. 'intraday_data').  The keys of each of these arrays will be the most natural
     * keys for that data - for example, the intraday_data key is the timestamp of the measurement.
     * The values associated with key is one final associative array containing all of the measurements
     * associated with that key; for example, for intraday_data, the value associated with each timestamp
     * is an array consisting of key/value pairs for keys 'load', 'n', and 'moe'.
     *
     * @return An array containing the important data scraped from LogNormal, as described above
     */
    private function getLogNormalDataFromJSONObject($dataset, $summary = array ()) {
        $data = array();

        // Get intraday data
        $intraday_data = array();
        if (array_key_exists('intraday_data', $dataset)) {
            foreach ($dataset['intraday_data']->rows as $row) {
                $contents = $row->c;

                $intraday_data[$contents[0]->v] =
                    array(
                        'load' => $contents[1]->v,
                        'n' => $contents[4]->v,
                        'moe' => $contents[5]->v
                    );
            }
        }
        $data['intraday_data'] = $intraday_data;

        // Get bounce rate data
        $bounce_data = array();
        if (array_key_exists('bounce_histogram_data', $dataset)) {
            foreach  ($dataset['bounce_histogram_data']->rows as $row) {
                $contents = $row->c;

                $bounce_data[$contents[0]->v] =
                    array(
                        'beacons' =>                $contents[1]->v,
                        'sessions' =>               $contents[2]->v,
                        'avg_pages_per_session' =>  $contents[3]->v,
                        'avg_mins_per_session' =>   $contents[4]->v,
                        'bounces' =>                $contents[5]->v,
                        'abandonments' =>           $contents[6]->v,
                        'bounce_rate' =>            $contents[7]->v,
                        'total_sales' =>            $contents[8]->v,
                        'items_sold' =>             $contents[9]->v,
                        'conversion_rate' =>        $contents[10]->v,
                    );
            }
        }
        $data['bounce_histogram_data'] = $bounce_data;

        // Get bandwidth data
        $bandwidth_data = array();
        if (array_key_exists('bandwidth_data', $dataset)) {
            foreach ($dataset['bandwidth_data']->rows as $row) {
                $contents = $row->c;
                $bandwidth_data[$contents[0]->v] = $contents[1]->v;
            }
        }
        $data['bandwidth_data'] = $bandwidth_data;

        // Get page group data
        $pagegroup_data = array();
        if (array_key_exists('page_group_data', $dataset)) {
            foreach ($dataset['page_group_data']->rows as $row) {
                $contents = $row->c;

                $pagegroup_data[$contents[0]->v] =
                    array(
                        'load' => $contents[1]->v,
                        'moe' => $contents[2]->v,
                        'n' => $contents[3]->v
                    );
            }
        }
        $data['pagegroup_data'] = $pagegroup_data;

        // Get histogram data
        $histogram_data = array();
        if (array_key_exists('histogram_data', $dataset)) {
            foreach ($dataset['histogram_data']->rows as $row) {
                $contents = $row->c;

                $histogram_data[$contents[0]->v] =
                    array(
                        'beacons' => $contents[1]->v
                    );
            }
        }
        $data['histogram_data'] = $histogram_data;

        // Get geo data
        $country_data = array();
        if (array_key_exists('geo_data', $dataset)) {
            foreach ($dataset['geo_data']->rows as $row) {
                $contents = $row->c;

                $country_data[$contents[3]->v] =
                    array(
                        'load' => $contents[1]->v,
                        'n' => $contents[2]->v,
                    );
            }
        }
        $data['country_data'] = $country_data;

        // Get browser data
        $browser_data = array();
        if (array_key_exists('browser_data', $dataset)) {
            foreach ($dataset['browser_data']->rows as $row) {
                $contents = $row->c;

                $browser_data[self::browser_heal($contents[0]->v)] =
                    array(
                        'load' => $contents[1]->v,
                        'moe' => $contents[2]->v,
                        'n' => $contents[3]->v
                    );
            }
        }
        $data['browser_data'] = $browser_data;

        // Add summary data
        $data['summary_data'] = $summary;

        return $data;
    }

    private static function drilldownToDataset($drilldown) {
        return strtolower($drilldown);
    }

    /**
     * Turns LogNormal's key for browsers into keys which separate digits from names with underscores
     */
    private static function browser_heal($browser_stupid_name) {
        // wtf, lognormal.
        return preg_replace('/(\w)(\d+)$/', "$1_$2", $browser_stupid_name);
    }

    /**
     * Given a string describing a bandwidth bucket, returns the code used by LogNormal to represent
     * that bucket in the URL
     */
    private static function getBandwidthCode($bandwidth_string) {
        $codes = array(
            "Lessthan64Kbps"    => 0,
            "64-512Kbps"        => 1,
            "512Kbps-2Mbps"     => 2,
            "2-6Mbps"           => 3,
            "6-10Mbps"          => 4,
            "10-100Mbps"        => 5,
            "100-1000Mbps"      => 6,
            "Morethan1Gbps"     => 7,
            "Other"             => 8
        );
        if (array_key_exists($bandwidth_string, $codes)) {
            return $codes[$bandwidth_string];
        }
        return $codes['Other'];
    }

    /**
     * Given an array specifying drilldown categories, returns the lognormal url required to access that
     * data
     *
     * @param date the date whose data the returned url should retreive
     * @param drilldown_specs an array specifying the desired drilldowns; should be of the form
     *      array ('pagegroup' => '<pagegroup>',
     *             'country'   => '<country>',
     *             'bandwidth' => '<bandwidth>',
     *             'browser'   => '<browser>')
     *
     * @return the url of the desired lognormal page
     *
     * @todo throw exception for invalid combinations
     */
    private function getLogNormalURL($date = null, $drilldown_specs = null) {
        $url = $this->base_url;

        if (!is_null($drilldown_specs)) {
            // btw, country drilldowns are not compatible with browser drilldowns
            $pagegroup =
                array_key_exists('pagegroup', $drilldown_specs) ? ('/pg/' . $drilldown_specs['pagegroup']) : '';
            $country =
                array_key_exists('country', $drilldown_specs)   ? ('/c/' . $drilldown_specs['country']) : '';
            $bandwidth =
                array_key_exists('bandwidth', $drilldown_specs) ? ('/bw/' . self::getBandwidthCode($drilldown_specs['bandwidth'])) : '';
            $browser =
                array_key_exists('browser', $drilldown_specs)   ? ('/u/' . $drilldown_specs['browser']) : '';

            $url .= ($bandwidth . $pagegroup . $browser . $country);
        }

        if (!is_null($date)) {
            $url .= '/' . $date->format(self::DATETIME_FORMAT);
        }

        return $url;
    }

    /**
     * Given the year, month, day, hour, minute, and second parameters to a new Date() call in the LogNormal
     * page, return the timestamp for that datetime.  Takes into account LogNormal nuances.
     *
     * @param int $year The year provided to the LogNormal new Date() call
     * @param int $month The month provided to the LogNormal new Date() call
     * @param int $day The day provided to the LogNormal new Date() call
     * @param int $hour The hour provided to the LogNormal new Date() call
     * @param int $minute The minute provided to the LogNormal new Date() call
     * @param int $second The second provided to the LogNormal new Date() call
     *
     * @return the unix timestamp for the given parameters
     */
    private static function getTimestampFromLogNormalDate($year, $month, $day, $hour = 0, $minute = 0) {
        // LogNormal thinks that January is the 0th month of the year.  Add one month to our timestamp
        // so that we can communicate properly with Graphite
        $month++;

        // Turn the params into a date
        $date = new DateTime();
        $date->setTimezone(new DateTimeZone('UTC'));
        $date->setDate($year, $month, $day);
        $date->setTime($hour, $minute);

        // Return the Unix timestamp
        return $date->format('U');
    }

    private function registerError($code, $message) {
        if (is_null($this->error)) {
            $this->error = array();
            $this->error['code'] = $code;
            $this->error['message'] = $message;
        }
    }

    /**
     * Returns a list of the possible drilldowns of the lowest specified tier given that we have drilled
     * through the higher-level tiers
     *
     * Examples:
     * getPossible...("Browser") => array("Chrome_21", "Firefox_14", ...);
     * getPossible...("Country", "US", "Browser") => array("Chrome_21", "Firefox_14", ...);
     *
     * If there is no IE_5 data for the US on the specified date, "IE_5" will not be included in the array
     * returned by getPossible("Country", "US", "Browser").
     */
    // @TODO private
    public function getPossibleTierValues($date, $tier1_name, $tier1_val = null, $tier2_name = null) {
        $tier1_name = self::drilldownToDataset($tier1_name);
        if (!is_null($tier2_name)) { $tier2_name = self::drilldownToDataset($tier2_name); }

        // Extract the dataset that we want
        $drilldown_specs = is_null($tier1_val) ? null : array($tier1_name => $tier1_val);
        $dataset_name = is_null($tier1_val) ? $tier1_name : $tier2_name;
        $ds = $this->getDataset($date, $dataset_name, $drilldown_specs);

        // Return the keys of that dataset
        return array_keys($ds);
    }
}
