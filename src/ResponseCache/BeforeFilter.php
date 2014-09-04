<?php
namespace Wowe\Cache\Response;

use Illuminate\Routing\Route;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BeforeFilter
{
    /**
     * Run the request filter
     * @param \Illuminate\Routing\Route $route
     * @param \Illuminate\Http\Request $request
     * @return mixed
     */
    public function filter(Route $route, Request $request)
    {
        return Handler::fireBeforeCallback($route, $request);
    }
}
