<?php namespace Wowe\Cache\Route;

use Illuminate\Support\ServiceProvider;
use Illuminate\Http\Request;
use Carbon\Carbon;

class RouteCacheServiceProvider extends ServiceProvider
{
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
        $this->app->before(function ($request) {
           // If the request type is GET and the requested format is HTML
            if ($request->method() === 'GET') { // && $request->format() === 'html') {
                if (!$request->headers->hasCacheControlDirective('no-cache')) {
                    if ($this->app['cache']->has($this->getCacheKey($request))) {
                        return '';
                    }
                }
            }
        });

        $this->app->after(function ($request, $response) {
            // If the request type was GET, the request format was HTML and the response is a HTML document
            if ($request->method() === 'GET') { // && $request->format() === 'html' && preg_match('/^text\/html;.*/', $response->headers->get('Content-Type'))) {
                $cacheKey = $this->getCacheKey($request);
                if ($request->headers->hasCacheControlDirective('no-cache') && $this->app['cache']->has($cacheKey)) {
                    $this->app['cache']->forget($cacheKey);
                }
                list($lastModified, $content) = $this->app['cache']->remember($cacheKey, (7 * 24 * 60), function () use ($response) {
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

    public function getCacheKey(Request $request)
    {
        return 'wowe.route-cache.' . md5($request->url());
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
