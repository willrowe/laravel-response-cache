Response Cache for Laravel
==========================
**Response Cache for Laravel** provides an easy way for route responses to be cached, handling the storage and retrieval of the response as well as the necessary headers to utilize client-side browser caching.

[Installation](#installation)  
[Configuration](#configuration)  
[Usage](#usage)  
[Release Notes](#release-notes)  
[Version Compatibility](#version-compatibility)  
[License](#license)  

Installation
------------
1. Add the package to your project's `composer.json` file.  
    - Command Line:  
        `composer require wowe/laravel-response-cache:1.0.*`
    - Edit Manually:

        ```
        "require": {
            "wowe/laravel-response-cache": "1.0.*"
        }
        ```
2. Update Composer.  
    `composer update`
3. Add the service provider to your `app.php` config file.  
    `'Wowe\Cache\Response\ServiceProvider'`

Configuration
-------------
The configuration file may be published to the `config` directory by using the command:

`php artisan config:publish response-cache`

*This is recommended since the configuration file that comes with the installation can be overridden by an update.*

**Available Settings**
- `enabled` (boolean)
    + Determines whether this package is turned on. If set to false, no responses will be cached, no matter the other settings. If set to true,caching will function normally. This is useful if you want to turn caching off in specific environments.
    + Default: true
- `life` (integer)
    + The number of minutes to cache the route responses. Only applies to routes which do not have cache life set in their action.
    + Default: 10080 minutes (1 week)
- `global` (boolean)
    + Whether to cache all get request responses. A value of true will cache all responses by default (inclusive mode). A value of false will cache no responses by default (exclusive mode).
    + Default: false

Usage
-----
###Simple Setup###
For a simple site you may cache all responses generated by routes using the `GET` method by setting the `global` config setting to `true`. To adjust how long these responses will be cached just change the value of the `life` config setting.

###Advanced Setup###
For projects where you need more control over which route responses are cached you may use the available route actions:
- `cache`
    + No value: if `"cache"` is set on the action as a string then it is equivalent to `"cache" => true` and will cache the route's response.
    + True: if `"cache" => true` is set on the action then the route's response will be cached.
    + False: if `"cache" => false` is set on the action then the route's response *will not* be cached.
    + Integer: if an integer is set on the action (`"cache" => 90`), then the route's response will be cached for that many minutes.
- `no-cache`
    + This is simply a semantic shortcut for `"cache" => false` and should be set as string on the action. *It cannot accept any value and should not appear in the action as a key.*
*These actions may be applied to either an individual route or a route group.*
- __*WARNING: DO NOT use more than one cache action on any single route as it will lead to unexpected behavior.*__
- __*CURRENT LIMITATION: DO NOT set any cache actions on a route or route group that is nested inside another route group which already has a cache action set. At this time it will not override any parent groups and will break caching.*__

###Examples:###
- Enable Caching
```php
// No value
Route::get('foo', ['FooController@getFoo', 'cache']);

// True
Route::get('bar', ['FooController@getBar', 'cache' => true]);

// Integer, will cache for 90 minutes
Route::get('baz', ['FooController@getBaz', 'cache' => 90]);

// Group
Route::group(['cache'], function () {
    Route::get('qux', 'FooController@getQux');
})
```
- Disable Caching
```php
// No value
Route::get('foo', ['FooController@getFoo', 'no-cache']);

// False
Route::get('bar', ['FooController@getBar', 'cache' => false]);

// Group
Route::group(['no-cache'], function () {
    Route::get('qux', 'FooController@getQux');
})
```

Release Notes
-------------
*Additional information can be found in the CHANGELOG.md file*
- v1.0.0 - Initial release

Version Compatibility
---------------------
Laravel | Response Cache
--------|---------------
4.2.x   | 1.0.x

License
-------
The **Route Response for Laravel** package is open-sourced software licensed under the [MIT license](http://opensource.org/licenses/MIT)