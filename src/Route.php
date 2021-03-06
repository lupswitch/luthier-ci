<?php

/**
 * Route class
 *
 * @autor Anderson Salas <anderson@ingenia.me>
 * @licence MIT
 */

namespace Luthier;

use Luthier\Exception\RouteNotFoundException;
use Luthier\RouteBuilder;

class Route
{
    /**
     * Route path (without prefix)
     *
     * @var $path
     *
     * @access private
     */
    private $path;


    /**
     * Route name
     *
     * @var $name
     *
     * @access private
     */
    private $name;


    /**
     * Route accepted methods (HTTP Verbs)
     *
     * @var $methods
     *
     * @access private
     */
    private $methods = [];


    /**
     * Route action, can be both a 'controller@method' string or a valid callback
     *
     * @var $action
     *
     * @access private
     */
    private $action;


    /**
     * Route middleware
     *
     * @var $middleware
     *
     * @access private
     */
    private $middleware = [];


    /**
     * Route pseudo-namespace  (is actually the directory path to the controller)
     *
     * @var $namespace
     *
     * @access private
     */
    private $namespace = '';


    /**
     * Route prefix
     *
     * @var $prefix
     *
     * @access private
     */
    private $prefix = '';


    /**
     * Array of route parameter objects (Luthier\RouteParam)
     *
     * @var $params
     *
     * @access public
     */
    public $params = [];



    /**
     * Route segment where starts the parameters
     *
     * @var $paramOffset
     *
     * @access public
     */
    public $paramOffset;


    /**
     * Does the route have optional parameters?
     *
     * @var $hasOptionalParams
     *
     * @access private
     */
    private $hasOptionalParams = false;


    /**
     * Is the current route a 404 page?
     *
     * @var $is404
     *
     * @access private
     */
    public $is404 = false;


    /**
     * Is the current route a CLI route?
     *
     * @var $isCli
     *
     * @access public
     */
    public $isCli = false;


    /**
     * Current request method
     *
     * @var $requestMethod
     *
     * @access public
     */
    public $requestMethod;


    /**
     * Get all compiled routes
     *
     * (Alias of RouteBuilder::getRoutes() )
     *
     * @return mixed
     *
     * @access public
     * @static
     */
    public static function getRoutes()
    {
        return RouteBuilder::getRoutes();
    }


    /**
     * Class constructor
     *
     * @param  string|array $methods HTTP Verbs
     * @param  array|callable $route Route definition
     *
     * @return void
     * @access public
     */
    public function __construct($methods, $route)
    {
        if($methods == 'any')
        {
            $methods = RouteBuilder::HTTP_VERBS;
        }
        elseif(is_string($methods))
        {

            $methods = [ strtoupper($methods) ];
        }
        else
        {
            array_shift($route);
        }

        foreach($methods as $method)
        {
            $this->methods[] = strtoupper($method);
        }

        // Required route attributes
        list($path, $action) = $route;
        $this->path = trim($path, '/') == '' ? '/' : trim($path, '/');

        if(!is_callable($action) && count(explode('@', $action)) != 2)
        {
            show_error('Route action must be in <strong>controller@method</strong> syntax or be a valid callback');
        }

        $this->action = $action;
        $attributes = isset($route[2]) && is_array($route[2]) ? $route[2] : NULL;

        // Route group inherited attributes
        if(!empty(RouteBuilder::getContext('prefix')))
        {
            $prefixes = RouteBuilder::getContext('prefix');
            foreach($prefixes as $prefix)
            {
                $this->prefix .= trim($prefix,'/') != '' ? '/' .trim($prefix, '/') : '';
            }
            $this->prefix = trim($this->prefix,'/');
        }

        if(!empty(RouteBuilder::getContext('namespace')))
        {
            $namespaces = RouteBuilder::getContext('namespace');
            foreach($namespaces as $namespace)
            {
                $this->namespace .= trim($namespace, '/') != '' ? '/' .trim($namespace, '/') : '';
            }
            $this->namespace = trim($this->namespace,'/');
        }

        if(!empty(RouteBuilder::getContext('middleware')['route']))
        {
            $middlewares = RouteBuilder::getContext('middleware')['route'];
            foreach($middlewares as $middleware)
            {
                if(!in_array($middleware, $this->middleware))
                {
                    $this->middleware[] = $middleware;
                }
            }
        }

        // Optional route attributes
        if($attributes !== NULL)
        {
            if(isset($attributes['namespace']))
            {
                $this->namespace = (!empty($this->namespace) ? '/' : '' ) . trim($attributes['namespace'], '/');
            }

            if(isset($attributes['prefix']))
            {
                $this->prefix .= (!empty($this->prefix) ? '/' : '' ) . trim($attributes['prefix'], '/');
            }

            if(isset($attributes['middleware']))
            {
                if(is_string($attributes['middleware']))
                {
                    $attributes['middleware'] = [ $attributes['middleware'] ];
                }

                $this->middleware = array_merge($this->middleware, array_unique($attributes['middleware']));
            }
        }

        // Parsing route parameters
        $_names   = [];
        $fullPath = trim($this->prefix,'/') != '' ? $this->prefix . '/' . $this->path : $this->path;
        $fullPath = trim($fullPath, '/') == '' ? '/' : trim($fullPath, '/');

        foreach(explode('/', $fullPath) as $i => $segment)
        {
            if(preg_match('/^\{(.*)\}$/', $segment))
            {
                if($this->paramOffset === null)
                {
                    $this->paramOffset = $i;
                }

                $param  = new RouteParam($segment);

                if(in_array($param->getName(), $_names))
                {
                    show_error('Duplicate route parameter <strong>' . $param->getName() . '</strong> in route <strong>"' .  $this->path . '</strong>"');
                }

                $_names[] = $param->getName();

                if( $param->isOptional() )
                {
                    $this->hasOptionalParams = true;
                }
                else
                {
                    if( $this->hasOptionalParams )
                    {
                        show_error('Required <strong>' . $param->getName() . '</strong> route parameter is not allowed at this position in <strong>"' . $this->path . '"</strong> route');
                    }
                }

                $this->params[] = $param;
            }
        }

        // Automatically set the default controller if path is "/"
        if($fullPath == '/' && in_array('GET', $this->methods))
        {
            RouteBuilder::$compiled['reserved']['default_controller'] = is_string($action)
                ?
                    ( empty($this->namespace) ? implode('/', explode('@', $action)) : RouteBuilder::DEFAULT_CONTROLLER )
                :
                    RouteBuilder::DEFAULT_CONTROLLER;
        }

        $this->isCli = is_cli();
    }


    /**
     * Compiles a Luthier-CI route into a CodeIgniter native route
     *
     * (This is used internally by Luthier)
     *
     * @param  string $currentMethod (Optional) Current HTTP Verb
     *
     * @return array
     *
     * @access public
     */
    public function compile($currentMethod = null )
    {
        $routes    = [];

        if($currentMethod === null)
        {
            if(is_array($this->methods) && !empty($this->methods))
            {
                $methods = $this->methods;
            }
            else
            {
                $methods = RouteBuilder::HTTP_VERBS;
            }
        }
        else
        {
            $methods = [ $currentMethod ];
        }

        foreach($methods as $verb)
        {
            $path   = $this->path;
            $target = null;

            if(!empty($this->prefix))
            {
                $path = trim($this->prefix . '/' . $path,'/');
            }

            if(is_callable($this->action))
            {
                $target = RouteBuilder::DEFAULT_CONTROLLER;
            }
            else
            {
                list($controller, $method) = explode('@', $this->action);
                $target = $controller . '/' . $method;

                if(!empty($this->namespace))
                {
                    $target = $this->namespace . '/' . $target;
                }

                foreach($this->params as $c => $param)
                {
                    $target .= '/$'.++$c;
                }
            }

            foreach($this->params as $param)
            {
                $path = str_ireplace($param->getSegment(), $param->getPlaceholder(), $path);
            }

            if($this->hasOptionalParams && $currentMethod === null)
            {
                $route = clone $this;

                do
                {
                    $param = array_pop($route->params);

                    if($param === null || !$param->isOptional())
                    {
                        $route->hasOptionalParams = false;
                        break;
                    }

                    $isOptional = $param->isOptional();
                    $routePath  = $route->getPath();
                    $routePath  = explode('/', $routePath);

                    array_pop($routePath);

                    $route->setPath(implode('/', $routePath));

                    $subRoute = $route->compile($verb);
                    $_path    = key($subRoute[0]);
                    $_target  = $subRoute[0][key($subRoute[0])][$verb];

                    $routes[][$_path][$verb] =  $_target;

                } while( $isOptional );
            }

            $routes[][$path][$verb] = $target;
        }

        $last = array_pop($routes);
        array_unshift($routes, $last);
        $routes = array_reverse($routes);

        return $routes;
    }


    /**
     * Get or set a route parameter
     *
     * @param  string  $name Parameter name
     * @param  string  $value (Optional) Parameter value
     *
     * @return null|string
     *
     * @access public
     */
    public function param($name, $value = null)
    {
        foreach($this->params as &$_param)
        {
            if($name == $_param->getName())
            {
                if($value !== null)
                {
                    $_param->value = $value;
                }
                return $_param->value;
            }
        }
    }


    /**
     * Check if the route has a specific parameter
     *
     * @param  string  $name
     *
     * @return bool
     *
     * @access public
     */
    public function hasParam($name)
    {
        foreach($this->params as &$_param)
        {
            if($name == $_param->getName())
            {
                return true;
            }
        }
        return false;
    }


    /**
     * Build the route url with the provided parameters
     *
     * @param  string|array $params Route parameters
     *
     * @return sting
     *
     * @access public
     */
    public function buildUrl($params)
    {
        $defaultParams = RouteBuilder::getDefaultParams();

        if(is_string($params))
        {
            $params = [ '*' => $params ];
        }
        else
        {
            if(!is_array($params))
            {
                $params = [];
            }
        }

        $path = $this->getPrefix() . '/' . $this->getPath();

        foreach($this->params as &$param)
        {
            $name = $param->getName();

            if(!$param->isOptional())
            {
                if(!isset($defaultParams[$name]) && !isset($params[$param->getName()]))
                {
                    throw new \Exception('Missing "' . $name .'" parameter for "' . $this->getName() . '" route');
                }

                if(isset($defaultParams[$name]))
                {
                    $param->value = $defaultParams[$param->getName()];
                }

                if(isset($params[$param->getName()]))
                {
                    $param->value = $params[$param->getName()];
                }

                $path = str_replace($param->getSegment(), $param->value, $path);
            }
            else
            {
                $_path = explode('/', $path);
                array_pop($_path);
                $path = implode('/', $_path);
                array_pop($this->params);
            }
        }

        return base_url() . trim($path,'/');
    }


    /**
     * Fluent name setter for a route
     *
     * @param  string $name
     *
     * @return Route
     * @access public
     */
    public function name($name)
    {
        $this->name = $name;
        return $this;
    }


    /**
     * Get route name
     *
     * @return string
     *
     * @access public
     */
    public function getName()
    {
        return $this->name;
    }


    /**
     * Set route name
     *
     * @param  string  $name
     *
     * @return void
     *
     * @access public
     */
    public function setName($name)
    {
        $this->name = $name;
    }


    /**
     * Get route path
     *
     * @return string
     *
     * @access public
     */
    public function getPath()
    {
        return $this->path;
    }


    /**
     * Set route path
     *
     * @param  string  $path
     *
     * @return void
     *
     * @access public
     */
    public function setPath($path)
    {
        $this->path = $path;
    }


    /**
     * Get route prefix
     *
     * @return string
     *
     * @access public
     */
    public function getPrefix()
    {
        return $this->prefix;
    }


    /**
     * Get route action
     *
     * @return string|callback
     *
     * @access public
     */
    public function getAction()
    {
        return $this->action;
    }


    /**
     * Set route action
     *
     * @param  string|callback   $action
     *
     * @return void
     *
     * @access public
     */
    public function setAction($action)
    {
        $this->action = $action;
    }


    /**
     * Get route middleware
     *
     * @return array
     *
     * @access public
     */
    public function getMiddleware()
    {
        return $this->middleware;
    }


    /**
     * Get route namespace
     *
     * @return string
     *
     * @access public
     */
    public function getNamespace()
    {
        return $this->namespace;
    }


    /**
     * Get route accepted HTTP Verbs
     *
     * @return mixed
     *
     * @access public
     */
    public function getMethods()
    {
        return $this->methods;
    }
}