<?php

/**
 * The server side of the shim API; handles queries based on URL params.
 */

require_once(__DIR__ . '/ShimQuery.php');
require_once(__DIR__ . '/ApiError.php');

date_default_timezone_set('UTC');

// Determine query information 
$query_type = empty($_GET['q']) ? 'empty' : $_GET['q'];

$tiers = array();
if (!empty($_GET['tier1'])) {
    array_push($tiers, array(
        'name'      =>  $_GET['tier1'],
        'values'    =>  isset($_GET[$_GET['tier1']]) ? explode(',', $_GET[$_GET['tier1']]) : null
    ));
    if (!empty($_GET['tier2'])) {
        array_push($tiers, array(
            'name'      =>  $_GET['tier2'],
            'values'    =>  isset($_GET[$_GET['tier2']]) ? explode(',', $_GET[$_GET['tier2']]) : null
        ));
    }
}


if (!isset($_COOKIE['lognorm_cookie'])) {
    $error = array('code' => ApiError::NO_COOKIE, 'message' => 'No cookie provided');
    echo(json_encode(array('response' => array('error' => $error))));
    exit;
} else if (!isset($_COOKIE['domain'])) {
    $error = array('code' => ApiError::NO_DOMAIN, 'message' => 'No domain provided');
    echo(json_encode(array('response' => array('error' => $error))));
    exit;
}

$lns = new LogNormalShim($_COOKIE['domain'], '_laptime_session=' . $_COOKIE['lognorm_cookie'] . ';');

$query = new ShimQuery($query_type, $_GET, $tiers, $lns);
$response = $query->getResponse();

// Respond
echo(json_encode(array('response' => $response), true));
