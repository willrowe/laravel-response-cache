<?php

namespace Wowe\Cache\Route;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
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
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        // Register the config file
        $this->app['config']->package(implode('/', [self::VENDOR, self::PACKAGE]), $this->guessPackagePath() . '/src/config');

        $this->app->before(function ($request) {
            if ($this->getConfig('cache-all') && $request->method() === 'GET') {
                if (!$request->headers->hasCacheControlDirective('no-cache')) {
                    if ($this->app['cache']->has($this->getCacheKey($request))) {
                        return '';
                    }
                }
            }
        });

        $this->app->after(function ($request, $response) {
            if ($this->getConfig('cache-all') && $request->method() === 'GET') {
                $cacheKey = $this->getCacheKey($request);
                if ($request->headers->hasCacheControlDirective('no-cache') && $this->app['cache']->has($cacheKey)) {
                    $this->app['cache']->forget($cacheKey);
                }
                list($lastModified, $content) = $this->app['cache']->remember($cacheKey, $this->getConfig('default-life'), function () use ($response) {
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
        });
    }
    /**
     * Generate a cache key, properly namespaced.
     * @param  Illuminate\Http\Request $request
     * @return  string
     */
    public function getCacheKey(Request $request)
    {
        return implode('.', [self::VENDOR, self::PACKAGE, md5($request->url())]);
    }

    /**
     * Get the configuration value for this package
     * @param string $name The name of the configuration value to retrieve
     * @return mixed
     */
    protected function getConfig($name)
    {
        return $this->app['config']->get(self::PACKAGE . '::' . $name);
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
