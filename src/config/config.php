<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Enable Response Caching
    |--------------------------------------------------------------------------
    |
    | Determines whether this package is turned on. If set to false, no
    | responses will be cached, no matter the other settings. If set to true,
    | caching will function normally. This is useful if you want to turn
    | caching off in other environments.
    */
    'enabled' => false,


    /*
    |--------------------------------------------------------------------------
    | Default Route Cache Life
    |--------------------------------------------------------------------------
    |
    | The number of minutes to cache the route responses.
    | Only applies to routes which do not have cache
    | life set in their action.
    | Default: 10080 minutes (1 week)
    */
    'life' => (7 * 24 * 60),

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
    'global' => false
];
