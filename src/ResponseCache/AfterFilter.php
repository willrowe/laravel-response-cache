<?php
namespace Wowe\Cache\Response;

use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AfterFilter
{
    /**
     * Run the request filter
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     * @param \Illuminate\Http\Response $response
     * @return mixed
     */
    public function filter(Route $route, Request $request, Response $response)
    {
        return Handler::fireAfterCallback($route, $request, $response);
    }
}
