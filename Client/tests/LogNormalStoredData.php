<?php

class LogNormalStoredData {

    function __construct($filename) {
        $this->filename = $filename;

        // Read in the file
        $json = file_get_contents("StoredData/$filename");
        $stored_data = json_decode($json, true);

        $this->date       = array_key_exists('date', $stored_data['metadata']) ?
                            $stored_data['metadata']['date'] : null;
        $this->pages      = array_key_exists('pages', $stored_data['metadata']) ?
                            $stored_data['metadata']['pages'] : null;
        $this->browsers   = array_key_exists('browsers', $stored_data['metadata']) ?
                            $stored_data['metadata']['browsers'] : null;
        $this->countries  = array_key_exists('countries', $stored_data['metadata']) ?
                            $stored_data['metadata']['countries'] : null;
        $this->bandwidths = array_key_exists('bandwidths', $stored_data['metadata']) ?
                            $stored_data['metadata']['bandwidths'] : null;

        $this->stored_results = $stored_data['data'];
        $this->comments = $stored_data['comments'];
    }

    public function getStoredResults($query) {
        if (!array_key_exists($query, $this->stored_results)) {
            throw new Exception(
                "I'm sorry, your pre-fetched query results are in another file: "
                . "results to '$query' not stored in " . $this->filename
            );
        }
        return json_encode($this->stored_results[$query]);
    }

    public function getStoredQueries() {
        return array_keys($this->stored_results);
    }

    public function getArrayOfRequiredFields($query) {
        $fields = array($this->date);

        // Break the function out into query and first/second-tier drilldowns
        preg_match(
            '/^(?:\w+)(?:By(\w+)(?:Then(\w+))?)?$/U',
            $query, $function
        );
        array_shift($function);     // The first field is the entire matched string

        foreach ($function as $reqfield_name) {
            if ($reqfield_name == 'PageGroup') {
                $field = $this->pages;
            } else if ($reqfield_name == 'Browser') {
                $field = $this->browsers;
            } else if ($reqfield_name == 'Country') {
                $field = $this->countries;
            } else if ($reqfield_name == 'Bandwidth') {
                $field = $this->bandwidths;
            }

            array_push($fields, $field);
        }

        return $fields;
    }
}
