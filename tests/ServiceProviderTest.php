<?php
use Wowe\Cache\ResponseCacheServiceProvider;
use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Carbon\Carbon;

class ServiceProviderTest extends Orchestra\Testbench\TestCase
{
    public function setUp()
    {
        parent::setUp();

        $this->setConfig(include(__DIR__ . '/../src/config/config.php'));
        
        $this->createServiceProvider();
    }

    private function createServiceProvider()
    {
        $this->serviceProvider = new ResponseCacheServiceProvider($this->app);
    }

    private function setConfig(array $config)
    {
        $this->packageConfiguration = $config;
        $this->app['config']->set('response-cache::config', $this->packageConfiguration);
        $this->createServiceProvider();
    }

    private function setConfigSetting($setting, $value)
    {
        $currentSettings = $this->packageConfiguration;
        $currentSettings[$setting] = $value;
        $this->setConfig($currentSettings);
    }

    private function generateRoute($method = 'GET', $uri = '/', $action = [])
    {
        return new Route($method, $uri, $action);
    }

    private function generateCacheTestRoute($uri = '/cache-test', $cache = true)
    {
        return $this->addRoute('GET', $uri, ['cache' => $cache, function () {
            return md5(mt_rand());
        }]);
    }

    private function generateRequest($method = 'GET', $uri = '/', $headers = null)
    {
        $request = Request::create($uri, $method);

        if (!is_null($headers)) {
            $request->headers->add($headers);
        }
        return $request;
    }

    private function addRoute($method = 'GET', $uri = '/', $action = [])
    {
        return call_user_func_array([$this->app['router'], strtolower($method)], [$uri, $action]);
    }

    private function generateRandomCacheLife()
    {
        do {
            $cacheLife = mt_rand(0, 5000);
        } while ($cacheLife === $this->packageConfiguration['life']);

        return $cacheLife;
    }

    /***********
     * TESTS
     **********/

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
    public function afterFilterShouldReturnFreshResponse()
    {
        $route = $this->generateCacheTestRoute();
        $request = $this->generateRequest('GET', '/cache-test', ['Cache-Control' => 'no-cache']);
        
        $response = $this->app['router']->dispatch($request);
        $response = $this->serviceProvider->routeAfterCallback($route, $request, $response);
        $this->assertTrue($response->isOk());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->has('Last-Modified'));
        $lastContent = $response->getContent();

        $response = $this->app['router']->dispatch($request);
        $response = $this->serviceProvider->routeAfterCallback($route, $request, $response);
        $this->assertTrue($response->isOk());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->has('Last-Modified'));
        $this->assertNotSame($response->getContent(), $lastContent);
    }

    /**
     * @test
     */
    public function afterFilterShouldReturnCachedResponse()
    {
        $route = $this->generateCacheTestRoute();
        $request = $this->generateRequest('GET', '/cache-test');
        
        $response = $this->app['router']->dispatch($request);
        $response = $this->serviceProvider->routeAfterCallback($route, $request, $response);
        $this->assertTrue($response->isOk());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->has('Last-Modified'));
        $lastContent = $response->getContent();

        $response = $this->app['router']->dispatch($request);
        $response = $this->serviceProvider->routeAfterCallback($route, $request, $response);
        $this->assertTrue($response->isOk());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->has('Last-Modified'));
        $this->assertSame($response->getContent(), $lastContent);
    }

    /**
     * @test
     */
    public function afterFilterShouldReturnNotModifiedResponse()
    {
        $route = $this->generateCacheTestRoute();
        $request = $this->generateRequest('GET', '/cache-test');
        
        $response = $this->app['router']->dispatch($request);
        $response = $this->serviceProvider->routeAfterCallback($route, $request, $response);
        $this->assertTrue($response->isOk());
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertTrue($response->headers->has('Last-Modified'));
        $lastContent = $response->getContent();

        $request->headers->set('If-Modified-Since', $response->headers->get('Last-Modified'));
        $response = $this->app['router']->dispatch($request);
        $response = $this->serviceProvider->routeAfterCallback($route, $request, $response);
        $this->assertSame($response->getStatusCode(), 304);
        $this->assertTrue($response->headers->hasCacheControlDirective('public'));
        $this->assertSame($response->getContent(), '');
    }

    /**
     * @test
     */
    public function beforeFilterShouldReturnNull()
    {
        $route = $this->generateCacheTestRoute();
        $request = $this->generateRequest('GET', '/cache-test');
        
        $response = $this->app['router']->dispatch($request);
        $this->serviceProvider->routeAfterCallback($route, $request, $response);
        $this->assertNull($this->serviceProvider->routeBeforeCallback($route, $this->generateRequest('GET', '/', ['Cache-Control' => 'no-cache'])));
    }

    /**
     * @test
     */
    public function beforeFilterShouldReturnEmptyString()
    {
        $route = $this->generateCacheTestRoute();
        $request = $this->generateRequest('GET', '/cache-test');
        
        $response = $this->app['router']->dispatch($request);
        $this->serviceProvider->routeAfterCallback($route, $request, $response);

        $this->assertSame($this->serviceProvider->routeBeforeCallback($route, $request), '');
    }
}
