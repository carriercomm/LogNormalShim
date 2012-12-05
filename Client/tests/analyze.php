<?php

require_once('LogNormalStoredData.php');

$dir = 'StoredData/';
$opts = getopt('f:', array('file:'));

$files = isset($opts['file']) ? array($opts['file']) : scandir($dir);

foreach ($files as $file) {
    if (!is_file("$dir$file")) { continue; }
    $sd = new LogNormalStoredData("$file");
    echo("File: $file\n");
    echo("Comments: $sd->comments\n");
    echo("Available query results for:\n");
    foreach ($sd->getStoredQueries() as $query) {
        echo("\t$query\n");
    }
    echo("\n");
}
