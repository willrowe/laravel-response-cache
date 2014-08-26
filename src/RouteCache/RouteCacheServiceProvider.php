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
     * Registers the matched callback on the router.
     * 
     * @param  Illuminate\Router\Route   $route
     * @param  Illuminate\Http\Request $request
     * 
     * @return void
     */
    public function routerMatchedCallback(Route $route, Request $request)
    {
        if ($this->routeShouldBeCached($route, $request)) {
            $this->registerRouteFilters($route);
        }
    }

    /**
     * Registers the filters for the beginning and end of routing and applies them
     *
     * @param Illuminate\Routing\Route $route
     * 
     * @return void
     */
    protected function registerRouteFilters(Route $route)
    {
        $beforeFilterName = $this->getFullPackageName('request', '.');
        $afterFilterName = $this->getFullPackageName('response', '.');
        $this->app['router']->filter($beforeFilterName, [$this, 'routeBeforeCallback']);
        $this->app['router']->filter($afterFilterName, [$this, 'routeAfterCallback']);
        $route->before($beforeFilterName);
        $route->after($afterFilterName);
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
        if ($this->respondsWithCached($route, $request)) {
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
        if ($this->requestedNoCache($request) && $this->routeIsCached($route)) {
            $this->app['cache']->forget($cacheKey);
        }
        list($lastModified, $content) = $this->app['cache']->remember($cacheKey, $this->getRouteCacheLife($route), function () use ($response) {
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
     * Whether the route meets the configuration (and other) criteria to be cached.
     * 
     * @param Illuminate\Routing\Route $route
     * @param Illuminate\Http\Request $request
     * 
     * @return boolean
     */
    public function routeShouldBeCached(Route $route, Request $request)
    {
        // HTTP Method
        if ($request->method() !== 'GET') {
            return false;
        }
        return $this->routeCacheEnabled($route);
    }

    /**
     * Whether the route is set to be cached.
     * Route cache action will override any global setting.
     * 
     * @param Illuminate\Routing\Route $route
     * 
     * @return boolean
     */
    public function routeCacheEnabled(Route $route)
    {
        $routeActionCacheValue = $this->getRouteActionCacheValue($route);
        
        if (is_null($routeActionCacheValue)) {
            return $this->config('global');
        }
        if (is_int($routeActionCacheValue)) {
            return true;
        }
        
        return $routeActionCacheValue;
    }

    /**
     * Gets the cache action set on the route.
     * 
     * @param Illuminate\Routing\Route $route
     * 
     * @return boolean|integer|null Returns null if no cache action was set.
     */
    public function getRouteActionCacheValue(Route $route)
    {
        $routeAction = $route->getAction();
        if (isset($routeAction['cache'])) {
            return $routeAction['cache'];
        }
        return $this->resolveKeylessCacheAction($routeAction);
    }

    /**
     * Searches the action array for the 'cache' or 'no-cache' directives.
     * 
     * @param array $routeAction
     * 
     * @return boolean|null Returns null if no cache directive is found.
     */
    protected function resolveKeylessCacheAction(array $routeAction)
    {
        if (in_array('cache', $routeAction)) {
            return true;
        }
        if (in_array('no-cache', $routeAction)) {
            return false;
        }
        return null;
    }

    /**
     * Whether the response will be from the cache.
     * 
     * @param Illuminate\Routing\Route $route
     * @param Illuminate\Http\Request $request
     * 
     * @return boolean
     */
    public function respondsWithCached(Route $route, Request $request)
    {
        return (!$this->requestedNoCache($request) && $this->routeIsCached($route));
    }

    /**
     * Whether the request had a 'no-cache' header
     * 
     * @param Illuminate\Http\Request $request
     * 
     * @return boolean
     */
    public function requestedNoCache(Request $request)
    {
        return $request->headers->hasCacheControlDirective('no-cache');
    }

    /**
     * Whether or not the route is already stored in the cache.
     * 
     * @param Illuminate\Routing\Route $route
     * 
     * @return boolean
     */
    public function routeIsCached(Route $route)
    {
        return $this->app['cache']->has($this->getCacheKey($route));
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
     * 
     * @param string $separator
     * @param string|array $append Any additional sections to append
     * 
     * @return string
     */
    public function getFullPackageName($append = null, $separator = '/')
    {
        return implode($separator, array_merge([self::VENDOR, self::PACKAGE], (array)$append));
    }

    /**
     * Generate a cache key, properly namespaced.
     * 
     * @param Illuminate\Routing\Route $route
     * 
     * @return string
     */
    public function getCacheKey(Route $route)
    {
        $routeHash = md5(is_null($route->getName()) ? $route->getUri(): $route->getName());
        return implode('.', [self::VENDOR, self::PACKAGE, $routeHash]);
    }

    /**
     * Get the configuration value for this package
     * 
     * @param string $settingName The name of the configuration value to retrieve
     * 
     * @return mixed
     */
    public function config($settingName)
    {
        if (is_null($this->config)) {
            $this->config = $this->app['config']->get(self::PACKAGE . '::config');
        }
        return $this->config[$settingName];
    }

    /**
     * Get the length of time the route should be cached for.
     * Route cache action value will override any global settings.
     * 
     * @param Illuminate\Routing\Route $route
     * 
     * @return integer|null
     */
    public function getRouteCacheLife(Route $route)
    {
        $routeActionCacheValue = $this->getRouteActionCacheValue($route);
        return is_int($routeActionCacheValue) ? $routeActionCacheValue : $this->config('life');
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
