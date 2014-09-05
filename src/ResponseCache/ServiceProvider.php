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
     * Indicates if loading of the provider is deferred.
     * @var bool
     */
    protected $defer = false;
    
    /**
     * The name to be used for the route before filter.
     * @var string
     */
    protected $beforeFilterName;

    /**
     * The name to be used for the route after filter.
     * @var string
     */
    protected $afterFilterName;

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
     * Register the service provider.
     * @return void
     */
    public function register()
    {
        $this->beforeFilterName = self::getFullPackageName('request', '.');
        $this->afterFilterName = self::getFullPackageName('response', '.');

        // Register the config file
        $this->app['config']->package(self::getFullPackageName(), $this->getPackagePath('config'));

        // Set the handler properties
        Handler::setProperties([
            'app' => $this->app,
            'config' => $this->app['config']->get(self::PACKAGE . '::config'),
            'cacheKeyPrefix' => self::getFullPackageName(null, '.'),
            'beforeFilterName' => $this->beforeFilterName,
            'afterFilterName' => $this->afterFilterName
        ]);

        // Register the filters
        $this->app['router']->filter($this->beforeFilterName, 'Wowe\Cache\Response\BeforeFilter');
        $this->app['router']->filter($this->afterFilterName, 'Wowe\Cache\Response\AfterFilter');

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
        Handler::make($route, $request);
    }
}
