<?php

/**
 * An object that contains information about a LogNormal shim API query and that can
 * get the query results
 */

require_once(__DIR__ . '/LogNormalShim.php');

class ShimQuery {

    private static $avail_map = array(
        'country'   => array( 'load', 'n', 'summary' ),
        'pagegroup' => array( 'load', 'n', 'moe', 'summary', 'intraday' ),
        'browser'   => array( 'load', 'n', 'moe', 'summary' ),
        'bandwidth' => array( 'beacons', 'summary' )
    );

    // Maps query names to the item of information that they seek from summary tables, null if 
    // a valid query seeks something else (a dataset, etc)
    private static $seeks_map = array (
        'getMedianLoadTime'  => 'load',
        'getPerc95LoadTime'  => 'perc95',
        'getPerc98LoadTime'  => 'perc98',
        'getNumberOfSamples' => 'n',
        'getMarginOfError'   => 'moe',

        'getBounceData'      =>  null,
        'getSummary'         =>  null,
        'getIntraday'        =>  null,
        'getIntradayRange'   =>  null,
        'getIntradayRangeByPageGroup'   =>  null,
    );

    // Maps query names to the datasets that they seek
    private static $dataset_map = array(
        'getMedianLoadTime'  =>  'summary',
        'getPerc95LoadTime'  =>  'summary',
        'getPerc98LoadTime'  =>  'summary',
        'getNumberOfSamples' =>  'summary',
        'getMarginOfError'   =>  'summary',

        'getSummary'         =>  'summary',
        'getBounceData'      =>  'bounce_histogram',
        'getIntraday'        =>  'intraday',
        'getIntradayRange'   =>  'intraday',
        'getIntradayRangeByPageGroup'   =>  'intraday',
    );

    /**
     * @return true iff all required fields are present
     */
    private function requireFields($required, $provided) {
        $this->missingFields = array();
        foreach($required as $required_field) {
            if (!array_key_exists($required_field, $provided)) {
                $this->missingFields[] = $required_field;
            }
        }
        return (empty($this->missingFields));
    }

    public function __construct($query, $fields, $tiers, $shim) {
        $this->shim = $shim;
        $this->tiers = $tiers;
        $this->num_tiers = count($tiers);
        $this->query = $query;

        // Get fields
        if ($this->query == 'getIntradayRange') {
            $this->range = true;
            if ($this->requireFields(array('start','end'), $_GET)) {
                $this->start = date_create('@' . $_GET['start']);
                $this->end = date_create('@' . $_GET['end']);

                // We're ignoring time and dealing with dates only for ranges
                $this->start->setTime(0,0,0);
                $this->end->setTime(0,0,0);
            }
        } else if ($this->query == 'getIntradayRangeByPageGroup') {
            $this->range = true;
            if ($this->requireFields(array('start','end'), $_GET)) {
                $this->start = date_create('@' . $_GET['start']);
                $this->end = date_create('@' . $_GET['end']);

                // We're ignoring time and dealing with dates only for ranges
                $this->start->setTime(0,0,0);
                $this->end->setTime(0,0,0);
            }
        } else if ($this->query == 'empty') {
            return;
        } else {
            $this->range = false;
            if ($this->requireFields(array('date'), $_GET)) {
                $this->date = date_create('@' . $fields['date']);
            }
        }

        $this->seeks = self::$seeks_map[$this->query];
    }

    private function getIntradayRangeByPageGroupResponse() {
        $data = array();
        $oneDay = new DateInterval('P1D');
        for ($date = clone $this->start; $date <= $this->end; $date = $date->add($oneDay)) {
            $pages = $this->shim->getPossibleTierValues($date, 'pagegroup');
            foreach ($pages as $page) {
                if (!isset($data[$page])) {
                    $data[$page] = array();
                }

                $data_today = array();
                $drilldown_specs = array('pagegroup' => $page);
                $data_today = $this->shim->getFromDataset($date, 'intraday', null, $drilldown_specs);
                if (array_key_exists('error', $data_today)) {
                    continue;
                };
                $data[$page] += $data_today;
            }
        }

        return $data;
    }

    private function getIntradayRangeResponse() {
        $data = array();
        $oneDay = new DateInterval('P1D');
        for ($date = clone $this->start; $date <= $this->end; $date = $date->add($oneDay)) {
            $data_today = $this->shim->getFromDataset($date, 'intraday');
            if (array_key_exists('error', $data_today)) {
                return $data;
            };
            $data += $data_today;
        }

        return $data;
    }

    private static function getErrorResponse($code, $msg) {
        return array('error' => array('code' => $code, 'message' => $msg));
    }

    public function getResponse() {
        if (!empty($this->missingFields)) {
            $missing = "'" . implode("', '", $this->missingFields) . "'";
            return self::getErrorResponse(
                ApiError::MISSING_FIELDS,
                "Missing fields $missing required for query '$this->query'"
            );
        }
        if ($this->query == 'empty') {
            return array('error' => array('code' => ApiError::NO_RESPONSE, 'message' => 'Query to API was empty'));
        } else if ($this->query == 'getIntradayRange') {
            return $this->getIntradayRangeResponse();
        } else if ($this->query == 'getIntradayRangeByPageGroup') {
            return $this->getIntradayRangeByPageGroupResponse();
        }

        // Check whether we can get the desired information from the display for the innermost tier
        $canGetFromDash =
            ($this->num_tiers == 0) || !is_null($this->seeks) || 
            (in_array($this->seeks, self::$avail_map[$this->tiers[$this->num_tiers - 1]['name']]));
        if ($canGetFromDash) {
            return $this->getFromDash($this->date);
        } else {
            return $this->scrapeAllOfThePages($this->date);
        }
    }

    private function scrapeAllOfThePages($date) {
        $seeks = $this->seeks;
        $data = array();

        // Get valid tier2 values if none are supplied
        $tier1_values = !is_null($this->tiers[0]['values']) ?
            $this->tiers[0]['values'] : $this->shim->getPossibleTierValues($date, $this->tiers[0]['name']);

        foreach ($tier1_values as $tier1_value) {
            $data[$tier1_value] = array();

            if ($this->num_tiers > 1) {
                // Get valid tier1 values if none are supplied
                $tier2_values = !is_null($this->tiers[1]['values']) ?
                    $this->tiers[1]['values'] : $this->shim->getPossibleTierValues($date, $this->tiers[0]['name'], $tier1_value, $this->tiers[1]['name']);
                foreach ($tier2_values as $tier2_value) {
                    $drilldown_specs = array($this->tiers[0]['name'] => $tier1_value,
                                             $this->tiers[1]['name'] => $tier2_value);
                    $dataset_to_grab = self::$dataset_map[$this->query];
                    $fresh = $this->shim->getDataset($date, $dataset_to_grab, $drilldown_specs);
                    if (array_key_exists('error', $fresh)) {
                        return $fresh;
                    }
                    if (!is_null($this->seeks)) {
                        $fresh = array_map(function($item) use ($seeks) {
                            if (array_key_exists($seeks, $item)) {
                                return $item[$seeks];
                            }
                            return null;
                        }, $fresh);
                    }
                    $data[$tier1_value][$tier2_value] = $fresh;
                }
            } else {
                $drilldown_specs = array($this->tiers[0]['name'] => $tier1_value);
                $dataset_to_grab = self::$dataset_map[$this->query];
                $fresh = $this->shim->getDataset($date, $dataset_to_grab, $drilldown_specs);
                if (array_key_exists('error', $fresh)) {
                    return $fresh;
                }
                if (!is_null($this->seeks)) {
                    $fresh = array_map(function($item) use ($seeks) {
                        if (array_key_exists($seeks, $item)) {
                            return $item[$seeks];
                        }
                        return null;
                    }, $fresh);
                }
                $data[$tier1_value][] = $fresh;
            }
        }
        return $data;
    }

    private function getFromDash($date) {
        $seeks = $this->seeks;
        if (count($this->tiers) == 0) {
            $data = $this->shim->getDataset($date, self::$dataset_map[$this->query]);
            if (array_key_exists('error', $data)) {
                return $data;
            } else if (is_null($seeks)) {
                return $data;
            } else {
                if (!array_key_exists($this->seeks, $data)) {
                    return null;
                }
                return $data[$this->seeks];
            }

        } else if (count($this->tiers) == 1) {
            $data =
                $this->shim->getFromDataset($date, $this->tiers[0]['name'], $this->tiers[0]['values']);
            if (array_key_exists('error', $data)) {
                return $data;
            }
            return array_map(function($item) use ($seeks) {
                if (array_key_exists($seeks, $item)) {
                    return $item[$seeks];
                } else {
                    return null;
                }
            }, $data);

        } else {
            $tier1_values = !is_null($this->tiers[0]['values']) ?
                $this->tiers[0]['values'] : $this->shim->getPossibleTierValues($date, $this->tiers[0]['name']);

            $data = array();
            foreach ($tier1_values as $tier1_value) {
                $drilldown_specs = array($this->tiers[0]['name'] => $tier1_value);
                $tier2 = $this->shim->getFromDataset(
                    $date, $this->tiers[1]['name'], $this->tiers[1]['values'], $drilldown_specs);
                if (array_key_exists('error', $tier2)) {
                    return $tier2;
                }
                $tier2 = array_map(function($item) use ($seeks) {
                    if (array_key_exists($seeks, $item)) {
                        return $item[$seeks];
                    } else {
                        return null;
                    }
                }, $tier2);
                $data[$tier1_value] = $tier2;
            }
        }

        return $data;
    }
}
