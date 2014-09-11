<?php

namespace Wowe\Cache\Response;

use Illuminate\Support\ServiceProvider as IlluminateServiceProvider;
use Illuminate\Routing\Route;
use Illuminate\Http\Request;

class ServiceProvider extends IlluminateServiceProvider
{
    const VENDOR = 'wowe';
    const PACKAGE = 'response-cache';

    /**
     * The name to be used for the route before filter.
     * @var string
     */
    protected static $beforeFilterName;

    /**
     * The name to be used for the route after filter.
     * @var string
     */
    protected static $afterFilterName;

    /**
     * Indicates if loading of the provider is deferred.
     * @var bool
     */
    protected $defer = false;

    /**
     * Get a configuration setting for this package
     * @param \Illuminate\Foundation\Application $app
     * @param string $settingName The name of the configuration setting to retrieve
     * @return mixed
     */
    public static function config($app, $settingName)
    {
        return $app['config']->get(self::PACKAGE . '::config.' . $settingName);
    }

    /**
     * Returns the vendor and package name.
     * 
     * @param string $separator
     * @param string|array $append Any additional sections to append
     * 
     * @return string
     */
    public static function getFullPackageName($append = null, $separator = '/')
    {
        return implode($separator, array_merge([self::VENDOR, self::PACKAGE], array_filter((array)$append)));
    }

    /**
     * Returns the before filter name.
     * @return string
     */
    public static function getBeforeFilterName()
    {
        return self::$beforeFilterName;
    }

    /**
     * Returns the after filter name.
     * @return string
     */
    public static function getAfterFilterName()
    {
        return self::$afterFilterName;
    }

    /**
     * Register the service provider.
     * @return void
     */
    public function register()
    {
        self::$beforeFilterName = self::getFullPackageName('request', '.');
        self::$afterFilterName = self::getFullPackageName('response', '.');

        // Register the config file
        $this->app['config']->package(self::getFullPackageName(), $this->getPackagePath('config'));

        // Register the filters
        $this->app['router']->filter(self::$beforeFilterName, 'Wowe\Cache\Response\BeforeFilter');
        $this->app['router']->filter(self::$afterFilterName, 'Wowe\Cache\Response\AfterFilter');

        // Register the 'route.matched' event
        $this->app['router']->matched(function (Route $route, Request $request) {
            $this->routerMatchedCallback($route, $request);
        });
    }

    /**
     * Get the services provided by the provider.
     * @return array
     */
    public function provides()
    {
        return array();
    }

    /**
     * The absolute path to the package.
     * @param string $append A path to be appended on to the end of the path
     * @return string
     */
    public function getPackagePath($append = null)
    {
        $append = is_null($append) ? '' : '/' . $append;
        return $this->guessPackagePath() . '/src' . $append;
    }

    /**
     * Registers the matched callback on the router.
     * @param Illuminate\Router\Route $route
     * @param Illuminate\Http\Request $request
     * @return void
     */
    protected function routerMatchedCallback(Route $route, Request $request)
    {
        Handler::make($this->app, $route, $request);
    }
}
