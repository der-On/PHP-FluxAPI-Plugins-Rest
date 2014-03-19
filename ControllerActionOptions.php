<?php
namespace Plugins\FluxAPI\Rest;

class ControllerActionOptions extends \FluxAPI\Options
{
    /**
     * Exclusive request method
     *
     * If null all methods will be registered, else only the method set
     * @var null
     */
    public $method = null;

    /**
     * Route override.
     *
     * If true automatic route will be generated, if false the action will have no route, if string the automatic route will be overriden.
     * @var bool
     */
    public $route = true;

    /**
     * Asserts for the route as array where keys are the parameter names and values are a regular expression.
     *
     * @var null
     */
    public $route_asserts = null;

    /**
     * Converts for the route as array where keys are the paramater names and values are the callbacks.
     *
     * @var null
     */
    public $route_converts = null;

    /**
     * Exlcusive output format
     *
     * @var null
     */
    public $output_format = null;
}