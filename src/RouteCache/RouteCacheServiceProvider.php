<?php

namespace Wowe\Cache\Route;

use Illuminate\Support\ServiceProvider;
use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;

class RouteCacheServiceProvider extends ServiceProvider
{
    const VENDOR = 'wowe';
    const PACKAGE = 'route-cache';

    /**
     * Indicates if loading of the provider is deferred.
     *
     * @var bool
     */
    protected $defer = false;

    /**
     * The configuration settings for this package.
     *
     * @var array
     */
    protected $config = null;
    
    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Register the config file
        $this->app['config']->package($this->getFullPackageName(), $this->getPackagePath('config'));

        $this->app['router']->matched([$this, 'routerMatchedCallback']);
    }

    /**
     * Registers the filters for the beginning and end of routing and applies them
     *
     * @param Illuminate\Routing\Route $route
     * 
     * @return void
     */
    public function registerRouteFilters(Route $route)
    {
        $beforeFilterName = $this->getFullPackageName('request', '.');
        $afterFilterName = $this->getFullPackageName('response', '.');
        $this->app['router']->filter($beforeFilterName, [$this, 'routeBeforeCallback']);
        $this->app['router']->filter($afterFilterName, [$this, 'routeAfterCallback']);
        $route->before($beforeFilterName);
        $route->after($afterFilterName);
    }

    /**
     * Registers the matched callback on the router.
     * @param  Illuminate\Router\Route   $route
     * @param  Illuminate\Http\Request $request
     * 
     * @return void
     */
    public function routerMatchedCallback(Route $route, Request $request)
    {
        if ($this->config('cache-all') && $request->method() === 'GET') {
            $this->registerRouteFilters($route);
        }
    }

    /**
     * Callback for the route before filter.
     * 
     * @param Illuminate\Router\Route $route
     * @param Illuminate\Http\Request $request
     * 
     * @return void
     */
    public function routeBeforeCallback(Route $route, Request $request)
    {
        if (!$request->headers->hasCacheControlDirective('no-cache') && $this->app['cache']->has($this->getCacheKey($route))) {
            return '';
        }
    }

    /**
     * Callback for the route after filter.
     * 
     * @param Illuminate\Router\Route $route
     * @param Illuminate\Http\Request $request
     * @param Illuminate\Http\Response $response
     * 
     * @return void
     */
    public function routeAfterCallback(Route $route, Request $request, Response $response)
    {
        $cacheKey = $this->getCacheKey($route);
        if ($request->headers->hasCacheControlDirective('no-cache') && $this->app['cache']->has($cacheKey)) {
            $this->app['cache']->forget($cacheKey);
        }
        list($lastModified, $content) = $this->app['cache']->remember($cacheKey, $this->config('default-life'), function () use ($response) {
            return [Carbon::now(), $response->getContent()];
        });
        $response->setContent($content);
        $response->setLastModified($lastModified);
        $response->setCache(['public' => true]);
        if ($request->headers->has('If-Modified-Since') && $request->headers->get('If-Modified-Since') === $response->headers->get('Last-Modified')) {
            $response->setNotModified();
        }
        return $response;
    }

    /**
     * The absolute path to the package.
     *
     * @param string $append A path to be appended on to the end of the path
     *
     * @return string
     */
    public function getPackagePath($append = null)
    {
        $append = is_null($append) ? '' : '/' . $append;
        return $this->guessPackagePath() . '/src' . $append;
    }

    /**
     * Returns the vendor and package name.
     * @param string $separator
     * @param string|array $append Any additional sections to append
     * @return string
     */
    public function getFullPackageName($append = null, $separator = '/')
    {
        return implode($separator, array_merge([self::VENDOR, self::PACKAGE], (array)$append));
    }

    /**
     * Generate a cache key, properly namespaced.
     * @param Illuminate\Routing\Route $route
     * @return string
     */
    public function getCacheKey(Route $route)
    {
        $routeHash = md5(is_null($route->getName()) ? $route->getUri(): $route->getName());
        return implode('.', [self::VENDOR, self::PACKAGE, $routeHash]);
    }

    /**
     * Get the configuration value for this package
     * @param string $settingName The name of the configuration value to retrieve
     * @return mixed
     */
    protected function config($settingName)
    {
        if (is_null($this->config)) {
            $this->config = $this->app['config']->get(self::PACKAGE . '::config');
        }
        return $this->config[$settingName];
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array();
    }
}
