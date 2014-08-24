<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Route Cache Life
    |--------------------------------------------------------------------------
    |
    | The number of minutes to cache the route responses.
    | Default: 10080 minutes (1 week)
    */
    'default-life' => (7 * 24 * 60),

    /*
    |--------------------------------------------------------------------------
    | Cache Scope
    |--------------------------------------------------------------------------
    |
    | Whether to cache all get request responses.
    | A value of true will cache all responses by default (inclusive mode)
    | A value of false will cache no responses by default (exclusive mode)
    | Default: false
    */
    'cache-all' => true
];
