<?php
use Illuminate\Routing\Route;
use Illuminate\Http\Response;
use \Mockery;

class ResponseCacheTest extends \Orchestra\Testbench\TestCase
{
    private $routeCount = 0;
    private $callCount = 0;

    public function setUp()
    {
        parent::setUp();

        $this->app['router']->enableFilters();
    }

    public function teardown()
    {
        Mockery::close();
    }

    protected function getPackageProviders()
    {
        return [
            'Wowe\Cache\Response\ServiceProvider'
        ];
    }

    /**
     * Change the package configuration
     * @param string $settingName
     * @param mixed $value
     */
    protected function setPackageConfig($settingName, $value)
    {
        $this->app['config']->set('response-cache::config.' . $settingName, $value);
    }

    protected function addRoute($action = [], $uri = null, $method = 'GET')
    {
        $router = $this->app['router'];

        if (!is_callable($action) && count(array_filter($action, 'is_callable')) == 0) {
            $action[] = function () {
                return md5(time() . ++$this->callCount);
            };
        }

        if (is_null($uri)) {
            $uri = md5('route' . ++$this->routeCount);
        }
        if (in_array($method, $router::$verbs)) {
            return call_user_func([$router, strtolower($method)], $uri, $action);
        }
    }

    /**
     * Call the passed route and returns the response
     * @param \Illuminate\Routing\Route $route
     * @return \Illuminate\Http\Response
     */
    protected function callRoute(Route $route = null)
    {
        if (is_null($route)) {
            $route = $this->addRoute();
        }
        return $this->call($route->methods()[0], $route->getUri());
    }

    protected function assertRouteResponseCached(Route $route = null)
    {
        if (is_null($route)) {
            $route = $this->addRoute();
        }
        // First call should be normal
        $response = $this->callRoute($route);
        $this->assertResponseCachedWithContent();
        $content = $response->getContent();

        // Second call should be cached
        $response = $this->callRoute($route);
        $this->assertResponseCachedWithContent($content);
    }

    protected function assertRouteResponseNotCached(Route $route = null)
    {
        if (is_null($route)) {
            $route = $this->addRoute();
        }
        // First call should be normal
        $response = $this->callRoute($route);
        $this->assertResponseNotCached();
        $content = $response->getContent();

        // Second call should not be cached
        $response = $this->callRoute($route);
        $this->assertResponseNotCached($content);
    }

    protected function assertRouteResponseCachedFor($life, Route $route = null)
    {
        Cache::shouldReceive('has')->twice()->andReturn(false);
        Cache::shouldReceive('put')->with(Mockery::type('string'), Mockery::type('array'), $life)->once();
        $this->callRoute($route);
    }

    protected function assertResponseCachedWithContent($content = null)
    {
        $response = $this->client->getResponse();
        $this->assertResponseOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->has('Last-Modified'));
        if (!is_null($content)) {
            $this->assertSame($response->getContent(), $content);
        }

        return $response;
    }

    protected function assertResponseNotCached($content = null)
    {
        $response = $this->client->getResponse();
        $this->assertResponseOk();
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
        $this->assertFalse($response->headers->has('Last-Modified'));

        if (!is_null($content)) {
            $this->assertNotEquals($response->getContent(), $content);
        }

        return $response;
    }

    public function testGlobalConfigCachesAllResponses()
    {
        $this->setPackageConfig('global', true);
        
        for ($i = 0; $i < 3; $i++) {
            $this->assertRouteResponseCached();
        }
    }

    public function testGlobalConfigDoesNotCacheAnyResponses()
    {
        $this->setPackageConfig('global', false);

        for ($i = 0; $i < 3; $i++) {
            $this->assertRouteResponseNotCached();
        }
    }

    public function testOnlyCachesGetRequests()
    {
        $this->setPackageConfig('global', true);
        $router = $this->app['router'];

        foreach (['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'] as $verb) {
            $route = $this->addRoute([], null, $verb);
            if ($verb === 'GET') {
                $this->assertRouteResponseCached($route);
            } else {
                $this->assertRouteResponseNotCached($route);
            }
        }
    }

    public function testLifeConfigIsUsed()
    {
        $life = mt_rand(1, 99999);
        $this->setPackageConfig('life', $life);
        $this->assertRouteResponseCachedFor($life);
    }

    public function testActionSettingsAreUsed()
    {
        $this->setPackageConfig('global', false);
        $this->assertRouteResponseCached($this->addRoute(['cache' => true]));
        $this->setPackageConfig('global', true);
        $this->assertRouteResponseNotCached($this->addRoute(['cache' => false]));
        $life = mt_rand(1, 99999);
        $this->setPackageConfig('life', null);
        $this->assertRouteResponseCachedFor($life, $this->addRoute(['cache' => $life]));
    }

    public function testKeylessActionsCanBeUsed()
    {
        $this->setPackageConfig('global', false);
        $this->assertRouteResponseCached($this->addRoute(['cache']));
        $this->setPackageConfig('global', true);
        $this->assertRouteResponseNotCached($this->addRoute(['no-cache']));
    }

    public function testResponseWillBeNotModifiedIfCachedAndModifiedSinceHeaderSent()
    {
        
    }

    public function testManuallyAddedRouteFiltersFail()
    {

    }

    public function testManuallyAddedRouteFiltersAreStripped()
    {

    }
}
