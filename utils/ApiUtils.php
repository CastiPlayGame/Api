<?php

class ApiUtils{
    public static function getHeader($headerName) {
        $headers = apache_request_headers();

        if (isset($headers[$headerName])) {
            return $headers[$headerName];
        }

        return null;
    }
    
}