<?php
namespace Wowe\Cache\Response;

use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;
use Illuminate\Foundation\Application;

class Handler
{
    /**
     * The configuration settings for this package.
     * 
     * @var array
     */
    protected static $config = null;

    /**
     * All the handler instances.
     * @var array
     */
    protected static $handlers = [];

    /**
     * The prefix for all cache keys
     * @var string
     */
    public static $cacheKeyPrefix;

    /**
     * The name of the before filter
     * @var string
     */
    public static $beforeFilterName;

    /**
     * The name of the after filter
     * @var string
     */
    public static $afterFilterName;

    /**
     * The application instance.
     * @var \Illuminate\Foundation\Application
     */
    public static $app;

    /**
     * The route being cached.
     * @var \Illuminate\Routing\Route
     */
    protected $route;

    /**
     * The request that triggered the route.
     * @var \Illuminate\Http\Request
     */
    protected $request;

    /**
     * The key by which to store and retrieve the response from the cache.
     * @var string
     */
    protected $cacheKey;

    /**
     * Get a configuration setting for this package
     * @param string $settingName The name of the configuration setting to retrieve
     * @return mixed
     */
    public static function config($settingName)
    {
        if (is_null(self::$config)) {
            self::$config = self::$app['config']->get('response-cache::config');
        }
        return self::$config[$settingName];
    }

    /**
     * Generate a cache key, properly namespaced.
     * @param \Illuminate\Routing\Route $route
     * @return string
     */
    protected static function generateCacheKey(Route $route)
    {
        $routeHash = md5(is_null($route->getName()) ? $route->getUri(): $route->getName());
        return implode('.', array_filter([self::$cacheKeyPrefix, $routeHash]));
    }

    /**
     * Whether the route meets the configuration (and other) criteria to be cached.
     * @param \Illuminate\Routing\Route $route
     * @param \Illumiante\Http\Request $request
     * @return boolean
     */
    protected static function responseCanBeCached(Route $route, Request $request)
    {
        // HTTP Method
        if ($request->method() !== 'GET') {
            return false;
        }
        // Configuration settings
        return self::resolveCachingSetting($route);
    }

    /**
     * Whether the route is set to be cached.
     * Route cache action will override any global setting.
     * @param \Illuminate\Routing\Route $route
     * @return boolean
     */
    protected static function resolveCachingSetting(Route $route)
    {
        $routeActionCacheValue = self::getActionCacheValue($route);
        
        if (is_null($routeActionCacheValue)) {
            return self::config('global');
        }
        if (is_int($routeActionCacheValue)) {
            return true;
        }
        
        return $routeActionCacheValue;
    }

    /**
     * Gets the cache action set on the route.
     * @param \Illuminate\Routing\Route $route
     * @return boolean|integer|null Returns null if no cache action was set.
     */
    protected static function getActionCacheValue(Route $route)
    {
        $routeAction = $route->getAction();
        if (isset($routeAction['cache'])) {
            return $routeAction['cache'];
        }
        return self::resolveKeylessCacheAction($routeAction);
    }

    /**
     * Get the length of time the route should be cached for.
     * Route cache action value will override any global settings.
     * @param \Illuminate\Routing\Route $route
     * @return integer|null
     */
    protected static function resolveCacheLife(Route $route)
    {
        $routeActionCacheValue = self::getActionCacheValue($route);
        return is_int($routeActionCacheValue) ? $routeActionCacheValue : self::config('life');
    }

    /**
     * Searches the action array for the 'cache' or 'no-cache' directives.
     * @param array $routeAction
     * @return boolean|null Returns null if no cache directive is found.
     */
    protected static function resolveKeylessCacheAction(array $routeAction)
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
     * If the response from the route will be cached then a new instance will be created.
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    public static function make(Route $route, Request $request)
    {
        if (self::responseCanBeCached($route, $request)) {
            self::$handlers[] = new static($route, $request);
        }
    }

    /**
     * Returns the instance that corresponds to the route and request.
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     * @return \Wowe\Cache\Response\Handler|null Returns null if instance not found;
     */
    public static function getInstance(Route $route, Request $request)
    {
        return array_first(self::$handlers, function ($index, $handler) use ($route, $request) {
            return $handler->matches($route, $request);
        });
    }

    /**
     * Fires the before callback on the correct instance.
     * @param Illuminate\Routing\Route $route
     * @param Illuminate\Http\Request $request
     * @return mixed
     */
    public static function fireBeforeCallback(Route $route, Request $request)
    {
        $instance = self::getInstance($route, $request);
        return is_null($instance) ? null : $instance->beforeCallback();
    }

    /**
     * Fires the after callback on the correct instance.
     * @param Illuminate\Routing\Route $route
     * @param Illuminate\Http\Request $request
     * @param Illuminate\Http\Response $respones
     * @return Illuminate\Http\Response
     */
    public static function fireAfterCallback(Route $route, Request $request, Response $response)
    {
        $instance = self::getInstance($route, $request);
        return is_null($instance) ? null : $instance->afterCallback($response);
    }

    /**
     * Create a new handler
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     */
    public function __construct(Route $route, Request $request)
    {
        $this->route = $route;
        $this->request = $request;

        $this->cacheKey = self::generateCacheKey($this->route);
        $this->cacheLife = self::resolveCacheLife($this->route);

        $this->refreshCache();
        $this->route->before(self::$beforeFilterName);
        $this->route->after(self::$afterFilterName);
    }

    /**
     * Whether the current instance has the same route and request.
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     * @return boolean
     */
    public function matches(Route $route, Request $request)
    {
        return ($route === $this->route && $request === $this->request);
    }

    /**
     * Callback for the route before filter.
     * @return mixed
     */
    public function beforeCallback()
    {
        if (!$this->request->isNoCache() && $this->responseIsCached()) {
            return '';
        }
    }

    /**
     * Callback for the route after filter.
     * @param \Illuminate\Http\Response $response
     * @return \Illuminate\Http\Response
     */
    public function afterCallback(Response $response)
    {
        list($lastModified, $content) = $this->getCachedResponse($response);
        $response->setContent($content);
        $response->setLastModified($lastModified);
        $response->setPublic();
        $response->isNotModified($this->request);
        return $response;
    }

    /**
     * Clears out old cached response if necessary.
     * @return void
     */
    protected function refreshCache()
    {
        if ($this->request->isNoCache() && $this->responseIsCached($this->route)) {
            self::$app['cache']->forget($this->cacheKey);
        }
    }

    /**
     * Retrieves the response from the cache and stores if not already cached.
     * @param \Illuminate\Http\Response $response
     * @return array
     */
    protected function getCachedResponse(Response $response)
    {
        if ($this->responseIsCached()) {
            return self::$app['cache']->get($this->cacheKey);
        }
        
        $cachedResponse = [Carbon::now(), $response->getContent()];
        self::$app['cache']->put($this->cacheKey, $cachedResponse, $this->cacheLife);
        return $cachedResponse;
    }

    /**
     * Whether or not the route response is already stored in the cache.
     * @return boolean
     */
    protected function responseIsCached()
    {
        return self::$app['cache']->has($this->cacheKey);
    }
}
