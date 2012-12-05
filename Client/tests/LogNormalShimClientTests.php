<?php

require_once('LogNormalStoredData.php');
require_once('../lib/LogNormalShimClient.php');

class LogNormalShimClientTests extends PHPUnit_Framework_Testcase {

    function setUp() {
        $this->ln = new LogNormalShimClient();
    }

    function functionDataProvider() {
        $files = array('basic.json', 'nsamples.json', 'notiers.json', 'getintraday.json', 'tier1_no_vals.json');

        $test_data = array();
        foreach ($files as $file) {
            $st = new LogNormalStoredData($file);
            foreach ($st->getStoredQueries() as $query) {
                $function_params = array($query, $st->getArrayOfRequiredFields($query), $st->getStoredResults($query));
                array_push($test_data, $function_params);
            }
        }

        return $test_data;

    }

    /**
    * @dataProvider functionDataProvider
    */
    function testFunction($function_name, $function_args, $query_results) {
        $data = $this->ln->__call($function_name, $function_args);
        assert(json_encode($data) == $query_results);
    }
}
