<?php

require_once(__DIR__.'/ApiError.php');

class LogNormalShimClient {

    /**
     * Error types; indicate which type of error occured and how to interpret
     * $this->error
     */
    const API_ERROR  = 1;       // $this->error is a LogNormalShimAPI error code
    const CURL_ERROR = 2;       // $this->error is a cURL error code
    const HTTP_ERROR = 3;       // $this->error is an HTTP error code

    /**
     * Gets the config settings from the config file and puts them into config array.
     * Checks that we have all of the necessary config settings.
     */
    function getConfig() {
        $path = __DIR__.'/../../config.json';
        $required_settings = array('domain', 'api_url', 'email', 'password');

        $this->config = json_decode(file_get_contents($path), true);
        if (!$this->config) {
            var_dump($this->config);
            throw new Exception('Could not parse config');
        }
        foreach ($this->config as $key => $value) {
            if ($key == 'email' || $key == 'password') {
                $login[$key] = $value;
                $this->config[$key]= 'check';
            }
        }

        foreach ($required_settings as $setting) {
            if (!array_key_exists($setting, $this->config)) {
                throw new Exception("Missing config setting $setting.  Please add this setting to your " .
                                    "config file at LogNormalShimClient/config.json.");
            }
        }

        $this->config['cookie'] = self::getLoginCookie($login['email'], $login['password']);
    }

    static function getLoginCookie($email, $password) {
        $url = 'https://app.lognormal.com/login';
        $ch = curl_init();
        $fields = "user[email]=$email&user[password]=$password";

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, 2);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fields);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!preg_match('/Set-Cookie:.*_laptime_session=([^;]*);/', curl_exec($ch), $m)) {  
            throw new Exception("Invalid LogNormal credentials");
        }
        curl_close($ch);
        return $m[1];
    }

    function __construct() {
        $this->getConfig();
    }

    /**
     * Checks that the given function is a valid combination of queries and drilldowns
     *
     * @return true iff the given function is valid
     */
    private function validateFunction($function) {
        $valid_queries = array(
            'getMedianLoadTime',
            'getMarginOfError',
            'getPerc95LoadTime',
            'getPerc98LoadTime',
            'getNumberOfSamples',
            'getSummary',
            'getIntradayRange',
            'getIntraday',
            'getBounceData',
        );
        $valid_drilldowns = array( 'Country', 'Browser', 'PageGroup', 'Bandwidth');

        $d = count($function) - 1;
        $valid =
            (in_array($function[0], $valid_queries) &&                  // Function is valid
             ($d < 1 || in_array($function[1], $valid_drilldowns)) &&   // First category is invalid
             ($d < 2 || in_array($function[2], $valid_drilldowns)) &&   // Second category is invalid
             ($d != 2 || $function[1] != $function[2]));                // Drill down by same category 2x

        return $valid;
    }

    /**
     * Validates that appropriate arguments are provided for the given valid function.
     * Fills $fields with fields to be supplied to the API to execute the requested query.
     *
     * @param function An array representation of the function called; the first field is the
     *        name of the query, the second the first drilldown, if it exists, and the third the
     *        second drilldown, if it exists.  ex. the representation of getSummaryByPageGroup would be
     *        array('getSummary', 'PageGroup').
     * @param the args to the function (ex. array(<unix timestamp>) for a function that requires a $date).
     *
     * @return true iff the function/argument combo is valid.
     */
    private function validateArgumentsAndGetFields($function, $args, &$fields) {
        $query_name = $function[0];
        $d = count($function) - 1;

        $fields = array('q' => $query_name);

        if ($query_name == 'getIntradayRange' || $query_name == 'getIntradayRangeByPageGroup') {
            if (count($args) < 2) {
                return false;
            }
            $fields['start'] = $args[0];
            $fields['end'] = $args[1];
            $i = 2;
        } else {
            if (count($args) < 1) {
                return false;
            }
            $fields['date'] = $args[0];
            $i = 1;
        }

        for ($k = 1; $k <= $d; $k++) {
            // If the user has specified specific values to filter for this field, record them as
            // $fields[<drilldown>] so that they may be passed on to the API; otherwise, set $fields[<drilldown>]
            // to null so that the API knows to grab data for ALL OF THE VALUES for that drilldown
            $fields["tier$k"] = strtolower($function[$k]);
            if (array_key_exists($i, $args) && is_array($args[$i])) {
                $fields[strtolower($function[$k])] = implode(',', $args[$i]);
            } else {
                $fields[strtolower($function[$k])] = null;
            }
            $i++;
        }

        return true;
    }

    /**
     * Valid functions: ($date is always a unix epoch timestamp)
     * getMedianLoadTime($date):        returns the dashboard median load time for the given date
     * getPerc95LoadTime($date):        returns the 95th percentile load time for the given date
     * getPerc98LoadTime($date):        returns the 98th percentile load time for the given date
     * getNumberOfSamples($date):       returns the dashboard number of median load time samples for the given date.
     * getMarginOfError($date):         returns the dashboard median load time moe for the given date.
     * getSummary($date):               returns the dashboard intraday performance summary data
     * getIntradayRange($start, $end):  returns the dashboard intraday performance data for the given range.
     *                                  @param $start the unix timestamp for the beginning of the range
     *                                  @param $end the unix timestamp for the end of the range
     * getIntradayRangeByPageGroup($start, $end):
     *                                  returns the dashboard intraday performance data for the given range
     *                                  drilled down by page group.
     * getIntraday($date):              returns the dashboard intraday performance data for the day specified by the given date.
     *                                  The returned array is of the form
     *                                  ( <timestamp> => ( 'load' => <median load time>,
     *                                                     'n'       => <number of samples>,
     *                                                     'moe'     => <margin of error> ),
     *                                    <timestamp> => ( ... ) )
     * getBounceData($date)             returns the bounce data for the given date
     */
    function __call($function_name, $args) {
        // Break the function out into query and first/second-tier drilldowns
        if ($function_name != 'getIntradayRangeByPageGroup') {
            preg_match(
                '/^(\w+)(?:By(\w+)(?:Then(\w+))?)?$/U',
                $function_name, $function
            );
            array_shift($function);         // First element of preg_match array is entire matched string
        } else {
            $function = array($function_name);
        }
        $query_name = $function[0];

        // Validate function name and drilldown options
        if ($function_name != 'getIntradayRangeByPageGroup' && !self::validateFunction($function)) {
            throw new BadFunctionCallException("Call to invalid function: $function_name");
        }

        // Validate that we have the required arguments
        if (!self::validateArgumentsAndGetFields($function, $args, $fields)) {
            throw new BadFunctionCallException("Invalid arguments passed to function $function_name");
        }

        // Perform the request
        if ($query_name == 'getIntradayRange' || $query_name == 'getIntradayRangeByPageGroup') {
            $fields['q'] = $query_name;
            $ranged_data = $this->queryAPI($fields);
            $start = $fields['start'];
            $end   = $fields['end'];

            $tight_range = array();
            if ($query_name == 'getIntradayRange') {
                foreach ($ranged_data as $key => $val) {
                    if ($key >= $start && $key < $end) {
                        $tight_range[$key] = $val;
                    }
                }
            } else {
                foreach ($ranged_data as $page => $page_data) {
                    $tight_range[$page] = array();
                    foreach ($page_data as $key => $val) {
                        if ($key >= $start && $key < $end) {
                            $tight_range[$page][$key] = $val;
                        }
                    }
                }
            }
            return $tight_range;
        }
        return $this->queryAPI($fields);
    }

    /**
     * Constructs a URL based on $fields and queries the API at that address;
     * returns the results of the query
     */
    private function queryAPI($fields) {
        $this->resetErrors();

        // Build equery url based on fields
        $query_url = $this->config['api_url'];
        $char = '?';
        foreach ($fields as $field => $value) {
            // We encode null by not encoding anything at all
            if (!is_null($value)) {
                $query_url .= "$char$field=$value";
                $char = '&';
            }
        }

        return $this->process($this->curlAPI($query_url));
    }

    /**
     * Queries the API at the given address; returns the results of the query
     *
     * @param $query_url the url to curl
     * @return the contents of the given url
     * @throws APIConectionException if there is a cURL or HTTP error while trying to reach the API
     */
    private function curlAPI($query_url) {
        // Set up cURL channel
        $channel = curl_init();
        curl_setopt($channel, CURLOPT_URL, $query_url);
        curl_setopt($channel, CURLOPT_COOKIE,
                    'lognorm_cookie=' . $this->config['cookie'] . ';' .
                    'domain=' . $this->config['domain'] . ';'
                   );

        curl_setopt($channel, CURLOPT_FRESH_CONNECT, true);             // @todo necessary?

        curl_setopt($channel, CURLOPT_SSL_VERIFYPEER, false);           // XXX do not do this
        curl_setopt($channel, CURLOPT_SSL_VERIFYHOST, false);           // XXX do not do this

        curl_setopt($channel, CURLOPT_RETURNTRANSFER, true);

        $lines = curl_exec($channel);

        // Report errors, if any occured
        if (curl_errno($channel) && !($this->error)) {
            $message = "Encountered cURL error attempting to access API: " . curl_error($channel);
            $this->registerError(self::CURL_ERROR, curl_errno($channel), $message);
        } else if ($http_error = curl_getinfo($channel, CURLINFO_HTTP_CODE >= 400)) {
            $message = "Encountered HTTP error $http_err attempting to access API";
            $this->registerError(self::HTTP_ERROR, $http_error, $message);
        }
        curl_close($channel);

        return json_decode($lines, true);
    }

    /**
     * Processes the results of a query to the API, setting the error flag and message if appropriate
     * and extracting and returning the response.
     */
    private function process($query_results) {
        if (is_array($query_results) && array_key_exists('response', $query_results)) {
            return $query_results['response'];
        } else if (is_array($query_results) && array_key_exists('error', $query_results)) {
            $this->registerError(self::API_ERROR, $query_results['error']['code'], $query_results['error']['message']);
            return array();
        } else {
            $this->registerError(self::API_ERROR, ApiError::NO_RESPONSE, "Empty response from API");
            return array();
        }
    }

    /**
     * Resets the error state for the client.  It is important to do this before running a new query
     * in order to ensure accurate error reporting.
     */
    private function resetErrors() {
        $this->error = 0;
    }


    /**
     * Registers an error for the most recent query, if one has not already been encountered.
     * Note that error information is only stored for the most recently executed query.
     */
    private function registerError($type, $number, $message) {
        if (!$this->error) {
            $this->errtype    = $type;
            $this->error      = $number;
            $this->errmessage = $message;
        }
    }
}
