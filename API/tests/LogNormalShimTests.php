<?php

require_once(__DIR__.'/../lib/LogNormalShim.php');

class LogNormalShimTests extends PHPUnit_Framework_Testcase {

    function summaryFixture() {
        return file_get_contents(__DIR__.'/fixtures/summary.html');
    }

    function testGetAll() {
        $shim = new LogNormalShim();
        $data = $shim->getAll(array('domain' => 'etsy.com', 'date' => '2012/7/1'));
        $this->assertEquals($data['median'], 2.1);
    }
}
