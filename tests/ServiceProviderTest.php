<?php
use Wowe\Cache\ResponseCacheServiceProvider;
use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ServiceProviderTest extends Orchestra\Testbench\TestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->packageConfiguration = include(__DIR__ . '/../src/config/config.php');
        $this->app['config']->set('response-cache::config', $this->packageConfiguration);
        $this->serviceProvider = new ResponseCacheServiceProvider($this->app);
    }

    private function generateRoute($method = 'GET', $uri = '/', $action = [])
    {
        return new Route($method, $uri, $action);
    }

    private function generateRequest($method = 'GET', $headers = null)
    {
        $request = Request::create('/', $method);

        if (!is_null($headers)) {
            $request->headers->add($headers);
        }
        return $request;
    }

    private function addRoute($method = 'GET', $action = [])
    {
        return call_user_func_array([$this->app['router'], strtolower($method)], ['/', $action]);
    }

    private function generateRandomCacheLife()
    {
        do {
            $cacheLife = mt_rand(0, 5000);
        } while ($cacheLife === $this->packageConfiguration['life']);

        return $cacheLife;
    }

    /**
     * @test
     */
    public function shouldOnlyCacheGetRequests()
    {
        $this->assertTrue($this->serviceProvider->responseShouldBeCached($this->generateRoute(), $this->generateRequest()));
        $this->assertFalse($this->serviceProvider->responseShouldBeCached($this->generateRoute('POST'), $this->generateRequest('POST')));
    }

    /**
     * @test
     */
    public function shouldReturnCorrectConfigurationSettings()
    {
        foreach ($this->packageConfiguration as $setting => $value) {
            $this->assertEquals($this->serviceProvider->config($setting), $value);
        }
    }

    /**
     * @test
     */
    public function shouldGetGlobalCacheLife()
    {
        $this->assertEquals($this->serviceProvider->getCacheLife($this->generateRoute()), $this->packageConfiguration['life']);
    }

    /**
     * @test
     */
    public function shouldGetRouteActionCacheLife()
    {
        $cacheLife = $this->generateRandomCacheLife();
        $route = $this->addRoute('GET', ['cache' => $cacheLife]);
        $this->assertEquals($this->serviceProvider->getCacheLife($route), $cacheLife);
    }

    /**
     * @test
     */
    public function shouldDetectNoCacheHeader()
    {
        $this->assertTrue($this->serviceProvider->freshResponseRequested($this->generateRequest('GET', ['Cache-Control' => 'no-cache'])));
        $this->assertFalse($this->serviceProvider->freshResponseRequested($this->generateRequest()));
    }

    /**
     * @test
     */
    public function shouldNotProvideAnything()
    {
        $provides = $this->serviceProvider->provides();
        $this->assertInternalType('array', $provides);
        $this->assertEmpty($provides);
    }

    /**
     * @test
     */
    public function shouldGenerateUniqueCacheKey()
    {
        $routeA = $this->generateRoute('GET', '/test1');
        $routeB = $this->generateRoute('GET', '/test2');

        $routeACacheKey = $this->serviceProvider->getCacheKey($routeA);
        $routeBCacheKey = $this->serviceProvider->getCacheKey($routeB);

        $this->assertInternalType('string', $routeACacheKey);
        $this->assertInternalType('string', $routeBCacheKey);
        $this->assertNotEquals($routeACacheKey, $routeBCacheKey);
    }

    /**
     * @test
     */
    public function shouldGenerateCorrectPackagePath()
    {
        $this->assertEquals($this->serviceProvider->getPackagePath(), realpath(__DIR__ . '/../src'));
    }

    /**
     * @test
     */
    public function shouldAppendToPackagePath()
    {
        $this->assertEquals($this->serviceProvider->getPackagePath('config'), realpath(__DIR__ . '/../src/config'));
    }

    /**
     * @test
     */
    public function shouldGetCorrectPackageName()
    {
        $this->assertEquals($this->serviceProvider->getFullPackageName(), 'wowe/response-cache');
    }

    /**
     * @test
     */
    public function shouldUseCorrectSeparatorInPackageName()
    {
        $this->assertEquals($this->serviceProvider->getFullPackageName(null, '.'), 'wowe.response-cache');
    }

    /**
     * @test
     */
    public function shouldAppendToPackageName()
    {
        $this->assertEquals($this->serviceProvider->getFullPackageName('config'), 'wowe/response-cache/config');
        $this->assertEquals($this->serviceProvider->getFullPackageName('config/config.php'), 'wowe/response-cache/config/config.php');
        $this->assertEquals($this->serviceProvider->getFullPackageName(['config', 'config.php']), 'wowe/response-cache/config/config.php');
    }

    /**
     * @test
     */
    public function shouldDetectCachedResponse()
    {
        $route = $this->generateRoute();
        $this->app['cache']->put($this->serviceProvider->getCacheKey($route), 'filler', 1);
        $this->assertTrue($this->serviceProvider->routeResponseIsCached($route));

        $this->assertFalse($this->serviceProvider->routeResponseIsCached($this->generateRoute('GET', '/test')));
    }

    /**
     * @test
     */
    public function shouldReadRouteActionCacheValue()
    {
        $this->assertTrue($this->serviceProvider->getRouteActionCacheValue($this->generateRoute('GET', '/', ['cache' => true])));
        $this->assertFalse($this->serviceProvider->getRouteActionCacheValue($this->generateRoute('GET', '/', ['cache' => false])));
        $this->assertTrue($this->serviceProvider->getRouteActionCacheValue($this->generateRoute('GET', '/', ['cache' => true])));

        $cacheLife = $this->generateRandomCacheLife();
        $minutes = $this->serviceProvider->getRouteActionCacheValue($this->generateRoute('GET', '/', ['cache' => $cacheLife]));
        $this->assertInternalType('integer', $minutes);
        $this->assertEquals($minutes, $cacheLife);

        $this->assertNull($this->serviceProvider->getRouteActionCacheValue($this->generateRoute()));
    }

    /**
     * @test
     */
    public function shouldSupportRouteActionsWithoutValues()
    {
        $this->assertTrue($this->serviceProvider->getRouteActionCacheValue($this->generateRoute('GET', '/', ['cache'])));
        $this->assertFalse($this->serviceProvider->getRouteActionCacheValue($this->generateRoute('GET', '/', ['no-cache'])));
    }
}
