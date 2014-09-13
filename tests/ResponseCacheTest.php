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

    /**
     * Adds a route to the application router.
     * If no URI is passed, a unique one is generated.
     * If no action is passed, a simple one is generated with a unique hash.
     * @param mixed $action
     * @param string $uri The URI to respond to
     * @param string $method A valid HTTP verb
     * @return \Illuminate\Router\Route
     */
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
    protected function callRoute(Route $route = null, array $requestHeaders = [])
    {
        if (is_null($route)) {
            $route = $this->addRoute();
        }
        if (!is_null($requestHeaders)) {
            $requestHeaders = array_combine(
                array_map(
                    function ($header) {
                        return 'HTTP_' . $header;
                    },
                    array_keys($requestHeaders)
                ),
                array_values($requestHeaders)
            );
        }
        return $this->call($route->methods()[0], $route->getUri(), [], [], $requestHeaders);
    }

    /*************
    * ASSERTIONS *
    *************/

    protected function assertRouteResponseCached(Route $route = null)
    {
        call_user_func_array([$this, 'assertRequestsForRoute'], array_merge([$route, ['assertResponseCachedWithContent']], array_slice(func_get_args(), 1)));
    }

    protected function assertRouteResponseNotCached(Route $route = null)
    {
        call_user_func_array([$this, 'assertRequestsForRoute'], array_merge([$route, ['assertResponseNotCached']], array_slice(func_get_args(), 1)));
    }

    protected function assertRequestsForRoute(Route $route = null, array $defaultAssertions = null, $numRequests = 2)
    {
        if (is_null($route)) {
            $route = $this->addRoute();
        }
        
        $data = null;
        $requests = array_slice(func_get_args(), 2);
        if (is_int($numRequests)) {
            $requests = array_merge($requests, array_fill(0, $numRequests, null));
        }
        foreach ($requests as $request) {
            if (!is_array($request) && !is_null($request)) {
                continue;
            }
            
            $request = array_merge([
                'headers' => [],
                'assertions' => (array)$defaultAssertions,
                'dataCallback' => function ($request, $response) {
                    return $response->getContent();
                }
            ], (array)$request);
            $parsedHeaders = array_map(
                function ($header) use ($data) {
                    if (is_callable($header)) {
                        return $header($data);
                    }
                    return $header;
                },
                $request['headers']
            );
            $response = $this->callRoute($route, $parsedHeaders);
            
            foreach ($request['assertions'] as $assertion) {
                call_user_func([$this, $assertion], $data);
            }
            
            if ($request['dataCallback'] !== false) {
                $data = is_callable($request['dataCallback']) ? $request['dataCallback']($request, $response) : $request['dataCallback'];
            }
        }
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

    protected function assertResponseCachedWithoutContent($lastModified = null)
    {
        $response = $this->client->getResponse();
        $this->assertResponseStatus(304);
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame($response->getContent(), '');

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

    protected function assertResponseFresh($content = null)
    {
        $response = $this->client->getResponse();
        $this->assertResponseOk();
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->has('Last-Modified'));

        if (!is_null($content)) {
            $this->assertNotEquals($response->getContent(), $content);
        }

        return $response;
    }

    /********
    * TESTS *
    ********/

    public function testGlobalConfigCachesAllResponses()
    {
        $this->setPackageConfig('global', true);
        $this->assertRouteResponseCached(null, 3);
    }

    public function testGlobalConfigDoesNotCacheAnyResponses()
    {
        $this->setPackageConfig('global', false);
        $this->assertRouteResponseNotCached(null, 3);
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
        $this->setPackageConfig('global', true);
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

    public function testNoCacheHeaderWillReturnFreshResponse()
    {
        $this->setPackageConfig('global', true);
        $this->assertRequestsForRoute(
            null,
            null,
            [
                'assertions' => ['assertResponseCachedWithContent']
            ],
            [
                'headers' => ['Cache-Control' => 'no-cache'],
                'assertions' => ['assertResponseFresh']
            ],
            [
                'assertions' => ['assertResponseCachedWithContent']
            ]
        );
    }

    public function testResponseWillBeNotModifiedIfCachedAndModifiedSinceHeaderSent()
    {
        $this->setPackageConfig('global', true);
        $this->assertRequestsForRoute(
            null,
            null,
            [
                'assertions' => ['assertResponseCachedWithContent'],
                'dataCallback' => function ($request, $response) {
                    return $response->headers->get('Last-Modified');
                }
            ],
            [
                'assertions' => ['assertResponseCachedWithoutContent'],
                'headers' => [
                    'If-Modified-Since' => function ($lastModified) {
                        return $lastModified;
                    }
                ],
                'dataCallback' => false
            ],
            [
                'assertions' => ['assertResponseCachedWithoutContent'],
                'headers' => [
                    'If-Modified-Since' => function ($lastModified) {
                        return $lastModified;
                    }
                ]
            ]
        );
    }

    public function testManuallyAddedRouteFiltersDoNotTriggerBeforeOrAfterFilters()
    {
        $this->app->instance('Wowe\Cache\Response\BeforeFilter', Mockery::mock('Wowe\Cache\Response\BeforeFilter')->shouldReceive('filter')->never()->getMock());
        $this->app->instance('Wowe\Cache\Response\AfterFilter', Mockery::mock('Wowe\Cache\Response\AfterFilter')->shouldReceive('filter')->never()->getMock());
        $route = $this->addRoute(['before' => 'wowe.response-cache.request', 'after' => 'wowe.response-cache.response']);
        $this->assertRouteResponseNotCached($route);
    }
}
