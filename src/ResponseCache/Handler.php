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
     * All the handler instances.
     * @var array
     */
    protected static $handlers = [];

    /**
     * The prefix for all cache keys
     * @var string
     */
    protected $cacheKeyPrefix;

    /**
     * The name of the before filter
     * @var string
     */
    protected $beforeFilterName;

    /**
     * The name of the after filter
     * @var string
     */
    protected $afterFilterName;

    /**
     * The application instance.
     * @var \Illuminate\Foundation\Application
     */
    protected $app;

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
     * Whether the route meets the configuration (and other) criteria to be cached.
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Routing\Route $route
     * @param \Illumiante\Http\Request $request
     * @return boolean
     */
    protected static function responseCanBeCached(Application $app, Route $route, Request $request)
    {
        // HTTP Method
        if (!in_array('GET', $route->methods(), true) || $request->method() !== 'GET') {
            return false;
        }
        // Configuration settings
        return self::resolveCachingSetting($app, $route);
    }

    /**
     * Whether the route is set to be cached.
     * Route cache action will override any global setting.
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Routing\Route $route
     * @return boolean
     */
    protected static function resolveCachingSetting(Application $app, Route $route)
    {
        $routeActionCacheValue = self::getActionCacheValue($route);
        
        if (is_null($routeActionCacheValue)) {
            return ServiceProvider::config($app, 'global');
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
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Routing\Route $route
     * @return integer|null
     */
    protected static function resolveCacheLife(Application $app, Route $route)
    {
        $routeActionCacheValue = self::getActionCacheValue($route);
        return is_int($routeActionCacheValue) ? $routeActionCacheValue : ServiceProvider::config($app, 'life');
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
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     * @return void
     */
    public static function make(Application $app, Route $route, Request $request)
    {
        if (self::responseCanBeCached($app, $route, $request)) {
            self::$handlers[] = new static($app, $route, $request);
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
     * @param \Illuminate\Foundation\Application $app
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     */
    private function __construct(Application $app, Route $route, Request $request)
    {
        $this->cacheKeyPrefix = ServiceProvider::getFullPackageName(null, '.');
        $this->beforeFilterName = ServiceProvider::getBeforeFilterName();
        $this->afterFilterName = ServiceProvider::getAfterFilterName();

        $this->app = $app;
        $this->route = $route;
        $this->request = $request;

        $this->generateCacheKey();
        $this->cacheLife = self::resolveCacheLife($this->app, $this->route);

        $this->refreshCache();
        $this->route->before($this->beforeFilterName);
        $this->route->after($this->afterFilterName);
    }

    /**
     * Generate a cache key, properly namespaced.
     * @return void
     */
    protected function generateCacheKey()
    {
        $routeHash = md5(is_null($this->route->getName()) ? $this->route->getUri(): $this->route->getName());
        $this->cacheKey = implode('.', array_filter([$this->cacheKeyPrefix, $routeHash]));
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
        if ($this->request->isNoCache() && $this->responseIsCached()) {
            $this->app['cache']->forget($this->cacheKey);
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
            return $this->app['cache']->get($this->cacheKey);
        }
        
        $cachedResponse = [Carbon::now(), $response->getContent()];
        $this->app['cache']->put($this->cacheKey, $cachedResponse, $this->cacheLife);
        return $cachedResponse;
    }

    /**
     * Whether or not the route response is already stored in the cache.
     * @return boolean
     */
    protected function responseIsCached()
    {
        return $this->app['cache']->has($this->cacheKey);
    }
}
