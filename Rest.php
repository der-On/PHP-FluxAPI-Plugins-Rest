<?php
namespace Plugins\FluxAPI\Rest;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use \FluxAPI\Query;

class Rest
{
    protected $_api = NULL;

    public $config = array(
        'base_route' => '',
        'default_input_format' => \FluxAPI\Api::DATA_FORMAT_ARRAY,
        'default_output_format' => 'json',
        'default_mime_type' => 'application/json',
    );

    public static function register(\FluxAPI\Api $api)
    {
        return new \Plugins\FluxAPI\Rest\Rest($api);
    }

    public function __construct(\FluxAPI\Api $api)
    {
        $this->_api = $api;

        if (!isset($this->_api->config['plugin.options']['FluxAPI/Rest'])) {
            $this->_api->config['plugin.options']['FluxAPI/Rest'] = $this->config;
        } else {
            $this->config = array_replace_recursive($this->config, $this->_api->config['plugin.options']['FluxAPI/Rest']);
        }

        $this->registerRoutes();
    }

    public function registerRoutes()
    {
        $this->registerControllerRoutes();
        $this->registerModelRoutes();
    }

    public function getUrlized($str)
    {
        // make lowercase
        $str = strtolower($str);

        // convert umlauts
        $str = preg_replace(array('/ä/', '/ö/', '/ü/'), array('ae', 'oe', 'ue'), $str);

        // convert to ascii only
        $str = iconv('UTF-8', 'ASCII//TRANSLIT', $str);

        // remove non alphanumeric characters
        $str = preg_replace("/[^a-zA-Z0-9\/_|+ -]/", '', $str);

        // trim and convert to lowercase
        $str = strtolower(trim($str, '-'));

        // convert spaces and such
        $str = preg_replace('/[\/_|+ -]+/', '-', $str);
        return $str;
    }

    public function getMimeTypeFromFormat($format, $default)
    {
        $format = ucfirst($format);

        $formats = $this->_api['plugins']->getPlugins('Format');

        if (in_array($format, array_keys($formats))) {
            $format_class = $formats[$format];
            return $format_class::getMimeType();
        } else {
            return $default;
        }
    }

    public function getFormatFromMimeType($mime_type, $default)
    {
        if (empty($mime_type)) {
            return $default;
        }

        $mime_type = strtolower(trim($mime_type));

        $formats = $this->_api['plugins']->getPlugins('Format');

        foreach($formats as $format => $format_class) {
            if ($format_class::getMimeType() == $mime_type) {
                return strtolower($format);
                continue;
            }
        }

        return $default;
    }

    public function getFormatFromExtension($ext, $default)
    {
        if (empty($ext)) {
            return $default;
        }

        $ext = strtolower(trim($ext));

        $formats = $this->_api['plugins']->getPlugins('Format');

        foreach($formats as $format => $format_class) {
            if ($format_class::getExtension() == $ext) {
                return strtolower($format);
                continue;
            }
        }

        return $default;
    }

    public function getExtensionFromFormat($format, $default)
    {
        if (empty($format)) {
            return $default;
        }

        $format = ucfirst(strtolower(trim($format)));

        $format_class = $this->_api['plugins']->getPluginClass('Format', $format);

        if ($format_class) {
            return $format_class::getExtension();
        }

        return $default;
    }

    public function getOutputFormat(Request $request)
    {
        return $this->config['default_output_format'];
    }

    public function getInputFormat(Request $request)
    {
        return $this->getFormatFromMimeType($request->headers->get('Content-Type'), $this->config['default_input_format']);
    }

    public function addFiltersToQueryFromRequest(Request $request, Query &$query)
    {
        $values = $request->query->all();

        foreach($values as $name => $value)
        {
            // ignore fields parameter as this is for narrowing down the fields to catch in a query
            // ignore 'raw' filter to prevent SQL injections
            if (in_array($name, array('fields','@raw'))) {
                if (substr($name,0,1) == '@') {
                    $query->filter(substr($name,1),explode(',',$value));
                } else {
                    $query->filter('equal',array($name,$value));
                }
            }
        }
    }

    public function getRequestData(Request $request, $format)
    {
        if ($format == \FluxAPI\Api::DATA_FORMAT_ARRAY) {
            $data = $request->request->all();
        } else {
            $data = $request->getContent();
        }

        return $data;
    }

    public function registerModelRoutes()
    {
        $self = $this;

        $models = $this->_api['plugins']->getPlugins('Model');

        foreach($models as $model_name => $model_class)
        {
            $model_route_name = $this->getUrlized($model_name);

            // view/load single model

            // with id and extension
            $this->_api->app->get($this->config['base_route'].'/'.$model_route_name.'/{id}.{ext}',
                function(Request $request, $id = NULL, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->loadModel($request, $model_name, $id, $format);
                }
            );
            // with id
            $this->_api->app->get($this->config['base_route'].'/'.$model_route_name.'/{id}',
                function(Request $request, $id = NULL) use ($self, $model_name) {
                    return $self->loadModel($request, $model_name, $id, $self->config['default_output_format']);
                }
            );
            // with no filters only and extension
            $this->_api->app->get($this->config['base_route'].'/'.$model_route_name.'.{ext}',
                function(Request $request, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->loadModel($request, $model_name, NULL, $format);
                }
            );
            // with no filters only
            $this->_api->app->get($this->config['base_route'].'/'.$model_route_name,
                function(Request $request) use ($self, $model_name) {
                    return $self->loadModel($request, $model_name, NULL, $self->config['default_output_format']);
                }
            );

            // view/load multiple models
            // with extension
            $this->_api->app->get($this->config['base_route'].'/'.$model_route_name.'s.{ext}',
                function(Request $request, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->loadModels($request, $model_name, $format);
                }
            );
            // without extension
            $this->_api->app->get($this->config['base_route'].'/'.$model_route_name.'s',
                function(Request $request) use ($self, $model_name) {
                    return $self->loadModels($request, $model_name, $self->config['default_output_format']);
                }
            );

            // create a new model
            // with extension
            $this->_api->app->post($this->config['base_route'].'/'.$model_route_name.'.{ext}',
                function(Request $request, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->createModel($request, $model_name, $format);
                }
            );
            // without extension
            $this->_api->app->post($this->config['base_route'].'/'.$model_route_name,
                function(Request $request) use ($self, $model_name) {
                    $format = $self->config['default_output_format'];
                    return $self->createModel($request, $model_name, $format);
                }
            );

            // update an existing a model
            // with id and extension
            $this->_api->app->put($this->config['base_route'].'/'.$model_route_name.'/{id}.{ext}',
                function(Request $request, $id, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->updateModel($request, $model_name, $id, $format);
                }
            );
            // with id
            $this->_api->app->put($this->config['base_route'].'/'.$model_route_name.'/{id}',
                function(Request $request, $id) use ($self, $model_name) {
                    return $self->updateModel($request, $model_name, $id, $self->config['default_output_format']);
                }
            );
            // with filters only and extension
            $this->_api->app->put($this->config['base_route'].'/'.$model_route_name.'.{ext}',
                function(Request $request, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->updateModel($request, $model_name, NULL, $format);
                }
            );
            // with filters only
            $this->_api->app->put($this->config['base_route'].'/'.$model_route_name,
                function(Request $request) use ($self, $model_name) {
                    return $self->updateModel($request, $model_name, NULL, $self->config['default_output_format']);
                }
            );

            // update multiple models
            // with extension
            $this->_api->app->put($this->config['base_route'].'/'.$model_route_name.'s.{ext}',
                function(Request $request, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->updateModels($request, $model_name, $format);
                }
            );
            // without extension
            $this->_api->app->put($this->config['base_route'].'/'.$model_route_name.'s',
                function(Request $request) use ($self, $model_name) {
                    return $self->updateModels($request, $model_name, $self->config['default_output_format']);
                }
            );

            // delete a single model
            // with id and extension
            $this->_api->app->delete($this->config['base_route'].'/'.$model_route_name.'/{id}.{ext}',
                function(Request $request, $id = NULL, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->deleteModel($request, $model_name, $id, $format);
                }
            );
            // with id
            $this->_api->app->delete($this->config['base_route'].'/'.$model_route_name.'/{id}',
                function(Request $request, $id = NULL) use ($self, $model_name) {
                    return $self->deleteModel($request, $model_name, $id, $self->config['default_output_format']);
                }
            );
            // with filters only and extension
            $this->_api->app->delete($this->config['base_route'].'/'.$model_route_name.'.{ext}',
                function(Request $request, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->deleteModel($request, $model_name, NULL, $format);
                }
            );
            // with filters only
            $this->_api->app->delete($this->config['base_route'].'/'.$model_route_name,
                function(Request $request) use ($self, $model_name) {
                    return $self->deleteModel($request, $model_name, NULL, $self->config['default_output_format']);
                }
            );

            // delete multiple models
            // with extension
            $this->_api->app->delete($this->config['base_route'].'/'.$model_route_name.'s.{ext}',
                function(Request $request, $ext = NULL) use ($self, $model_name) {
                    $format = $self->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->deleteModels($request, $model_name, $format);
                }
            );
            // without extension
            $this->_api->app->delete($this->config['base_route'].'/'.$model_route_name.'s',
                function(Request $request) use ($self, $model_name) {
                    return $self->deleteModels($request, $model_name, $self->config['default_output_format']);
                }
            );


            // count models
            // with extension
            $this->_api->app->get($this->config['base_route'] . '/' . $model_route_name . 's/count.{ext}',
                function(Request $request, $ext) use ($self, $model_name) {
                    $format = $this->getFormatFromExtension($ext, $self->config['default_output_format']);
                    return $self->countModels($request, $model_name, $format);
                }
            );

            // without extension
            $this->_api->app->get($this->config['base_route'] . '/' . $model_route_name . 's/count',
                function(Request $request) use ($self, $model_name) {
                    return $self->countModels($request, $model_name, $self->config['default_output_format']);
                }
            );
        }
    }

    public function registerControllerRoutes()
    {
        $self = $this;
        $actions = $this->_api['controllers']->getActions();

        foreach($actions as $controller_name => $_actions) {
            $controller_route_name = $this->getUrlized($controller_name);

            foreach($_actions as $action => $options) {
                $options = isset($options['Plugins/FluxAPI/Rest']) ? $options['Plugins/FluxAPI/Rest'] : new ControllerActionOptions();

                if (is_array($options)) {
                    $options = new ControllerActionOptions($options);
                }

                // action has no route
                if (!$options->route) {
                    continue;
                }
                // action overrides route
                elseif (is_string($options->route) && !empty($options->route)) {
                    $route = $this->config['base_route'] . '/' . $options->route;
                }
                // no explicit route given, so generate it
                elseif ($options->route === true) {
                    // index routes do not have the action name appended to the route
                    if ($action == 'index') {
                        $action_route_name = '';
                    } else {
                        $action_route_name = $this->getUrlized($action);
                    }

                    // TODO: guess the prefered method using action prefixes: set, get, update

                    $route = $this->config['base_route'] . '/' . $controller_route_name;
                    if (!empty($action_route_name)) {
                        $route .= '/' . $action_route_name;
                    }
                }

                // action has explicit method
                if (isset($options->method) && in_array(strtolower($options->method), array('get','post','put','delete','update'))) {
                    $method = strtoupper($options->method);
                } else {
                    $method = null;
                }

                // action has explicit output format
                if (isset($options->output_format)) {
                    $format = $options->output_format;
                    $ext = $this->getExtensionFromFormat($format, $this->config['default_output_format']);
                }
                // action has no explicit output format
                else {
                    $format = FALSE;
                }

                // no explicit format set, using all
                if ($format === FALSE) {
                    // with extension
                    $_route = $this->_api->app->match($route . '.{ext}',
                        function(Request $request, $ext) use ($self, $controller_name, $action) {
                            $format = $this->getFormatFromExtension($ext, $self->config['default_output_format']);
                            return $self->callController($request, $controller_name, $action, $format);
                        }
                    );

                    if (!empty($method)) {
                        $_route->method($method);
                    }
                // explicit format set, using only that
                } else {
                    $_route = $this->_api->app->match($route . '.' . $ext,
                        function(Request $request) use ($self, $controller_name, $action, $format) {
                            return $self->callController($request, $controller_name, $action, $format);
                        }
                    );

                    if (!empty($method)) {
                        $_route->method($method);
                    }
                }

                // add route asserts if any
                if (isset($options->route_asserts)) {
                    foreach($options->route_asserts as $key => $assert) {
                        $_route->assert($key, $assert);
                    }
                }

                // without extension
                if ($format === FALSE || $format == $this->config['default_output_format']) {
                    $_route = $this->_api->app->match($route,
                        function(Request $request) use ($self, $controller_name, $action) {
                            return $self->callController($request, $controller_name, $action, $self->config['default_output_format']);
                        }
                    );

                    if (!empty($method)) {
                        $_route->method($method);
                    }

                    // add route asserts if any
                    if (isset($options->route_asserts)) {
                        foreach($options->route_asserts as $key => $assert) {
                            $_route->assert($key, $assert);
                        }
                    }
                }
            }
        }
    }

    public function createModel(Request $request, $model_name, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $input_format = $this->getInputFormat($request);

            $data = $this->getRequestData($request, $input_format);

            $create_method = 'create'.$model_name;

            try {
                $model = $this->_api->$create_method($data, $input_format);
            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }

            try {
                if ($this->_api->save($model_name, $model)) {
                    return $this->_createModelSuccessResponse($model, $model_name, $format);
                } else {
                    return $this->_createErrorResponse(new \ErrorException('Error during creation of resource.'), $format);
                }

            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function updateModel(Request $request, $model_name, $id = NULL, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $input_format = $this->getInputFormat($request);

            $data = $this->getRequestData($request, $input_format);

            $query = new Query();

            if(!empty($id)) {
                $query->filter('equal',array('id',$id));
            }

            $this->addFiltersToQueryFromRequest($request, $query);

            try {
                $result = $this->_api->updateFirst($model_name, $query, $data, $input_format);

                if ($result) {
                    return $this->_createModelSuccessResponse($result, $model_name, $format, TRUE);
                } else {
                    return $this->_createErrorResponse(new \ErrorException('Error during update of resource.'), $format);
                }


            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }

        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function updateModels(Request $request, $model_name, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $input_format = $this->getInputFormat($request);

            $data = $this->getRequestData($request, $input_format);

            $query = new Query();

            $this->addFiltersToQueryFromRequest($request, $query);

            try {
                $result = $this->_api->update($model_name, $query, $data, $input_format);

                if ($result) {
                    return $this->_createModelSuccessResponse($result, $model_name, $format, TRUE);
                } else {
                    return $this->_createErrorResponse(new \ErrorException('Error during update of resources.'), $format);
                }
            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function loadModel(Request $request, $model_name, $id = NULL, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $query = new Query();

            if (!empty($id)) {
                $query->filter('equal',array('id',$id));
            }

            $this->addFiltersToQueryFromRequest($request, $query);

            try {
                $result = $this->_api->loadFirst($model_name, $query, $format);

                if ($result) {
                    return $this->_createModelSuccessResponse($result, $model_name, $format, FALSE);
                } else {
                    return $this->_createErrorResponse(new \ErrorException('Error during load of resource.'), $format);
                }
            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function loadModels(Request $request, $model_name, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $query = new Query();

            $this->addFiltersToQueryFromRequest($request, $query);

            try {
                $result = $this->_api->load($model_name, $query, $format);

                if ($result) {
                    return $this->_createModelSuccessResponse($result, $model_name, $format, FALSE);
                } else {
                    return $this->_createErrorResponse(new \ErrorException('Error during load of resources.'), $format);
                }
            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function deleteModel(Request $request, $model_name, $id = NULL, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $query = new Query();

            if (!empty($id)) {
                $query->filter('equal',array('id',$id));
            }

            $this->addFiltersToQueryFromRequest($request, $query);

            try {
                $result = $this->_api->deleteFirst($model_name, $query);

                if ($result) {
                    return $this->_createSuccessResponse(array('success' => true), $format);
                } else {
                    return $this->_createErrorResponse(new \ErrorException('Error during delete of resource.'), $format);
                }
            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function deleteModels(Request $request, $model_name, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $query = new Query();

            $this->addFiltersToQueryFromRequest($request, $query);

            try {
                $result = $this->_api->delete($model_name, $query);

                if ($result) {
                    return $this->_createSuccessResponse(array('success' => true), $format);
                } else {
                    return $this->_createErrorResponse(new \ErrorException('Error during delete of resources.'), $format);
                }
            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function countModels(Request $request, $model_name, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Model',$model_name)) {
            $query = new Query();

            $this->addFiltersToQueryFromRequest($request, $query);

            try {
                $result = array( 'count' => $this->_api->count($model_name, $query) );

                return $this->_createSuccessResponse($result, $format);
            } catch (\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Model "%s" does not exist.', $model_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    public function callController(Request $request, $controller_name, $action, $format)
    {
        if ($this->_api['plugins']->hasPlugin('Controller',$controller_name)) {
            $input_format = $this->getInputFormat($request);

            $data = $this->getRequestData($request, $input_format);
            $query = $request->query->all();

            $params = array_merge($data, $query);

            try {
                $result = $this->_api['controllers']->call($controller_name, $action, $params, array(
                    'request' => $request,
                    'output_format' => $format,
                    'input_format' => $input_format,
                ));

                // if controller returns a response we pass it back directly
                $response_class = '\\Symfony\\Component\\HttpFoundation\\Response';
                if (is_object($result) && ($result instanceof $response_class)) {
                    return $result;
                } else {
                    return $this->_createSuccessResponse($result, $format);
                }
            } catch(\Exception $error) {
                return $this->_createErrorResponse($error, $format);
            }
        } else {
            $error = new \InvalidArgumentException(sprintf('Controller "%s" does not exist.', $controller_name));
            return $this->_createErrorResponse($error, $format);
        }
    }

    protected function _createResponse($data, $status, $format, $encode_data = TRUE)
    {
        $formats = $this->_api['plugins']->getPlugins('Format');

        if ($encode_data && isset($formats[ucfirst($format)])) {
            $format_class = $formats[ucfirst($format)];
            $format_class::setApi($this->_api);
            $data = $format_class::encode($data);
        }

        return new Response(
            $data,
            $status,
            array('Content-Type'=>$this->getMimeTypeFromFormat($format, $this->config['default_mime_type']))
        );
    }

    protected function _createModelSuccessResponse($data, $model_name, $format, $encode_data = TRUE)
    {
        $formats = $this->_api['plugins']->getPlugins('Format');

        // model collection
        if (is_array($data)) {
            $model_name .= 's';
        }
        // single model
        elseif (is_object($data)) {
            $data = $data->toArray();
        }

        if ($encode_data && isset($formats[ucfirst($format)])) {
            $format_class = $formats[ucfirst($format)];
            $format_class::setApi($this->_api);
            $data = $format_class::encode($data, array('root' => $model_name));
        }

        return new Response(
            $data,
            200,
            array('Content-Type'=>$this->getMimeTypeFromFormat($format, $this->config['default_mime_type']))
        );
    }

    protected function _createSuccessResponse($data, $format, $encode_data = TRUE)
    {
        return $this->_createResponse($data, 200, $format, $encode_data);
    }

    protected function _createErrorResponse(\Exception $error, $format, $encode_data = TRUE)
    {
        $arr = array(
            'error' => array(
                'message' => $error->getMessage(),
                'code' => $error->getCode(),
            )
        );

        if ($this->_api->config['debug']) {
            $arr['error']['file'] = $error->getFile();
            $arr['error']['line'] = $error->getLine();
            $arr['error']['trace'] = $error->getTraceAsString();
        }

        return $this->_createResponse(
            $arr,
            500,
            $format,
            $encode_data
        );
    }
}
