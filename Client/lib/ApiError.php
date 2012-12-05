<?php

/**
 * Defines error codes that the API uses.  The declarations in this file should match those
 * in the API file (@todo or use strings - ex code returned by API would be 'NO_RESPONSE', and
 * we only store the int value in client)
 */

class ApiError {
    const NO_RESPONSE           =   1;  /* No (empty) response from the API */

    const NO_COOKIE             =   2;  /* No cookie was provided and the LogNormal page could therefore not be accessed */

    const NO_DOMAIN             =   3;  /* No domain was provided and the LogNormal page could therefore not be accessed */

    const LOGNORMAL_CURL_ERROR  =   4;  /* cULR error when trying to access LogNormal */

    const LOGNORMAL_HTTP_ERROR  =   5;  /* HTTP error while trying access LogNormal */

    const LOGNORMAL_NO_DATA     =   6;  /* LogNormal page was cURL'd successfully, but no data
                                           could be extracted from its contents */

    const MISSING_FIELDS        =   7;  /* Not all necessary fields for the query were provided */
}
