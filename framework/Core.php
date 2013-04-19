<?php
namespace framework {

    use framework\exceptions\CoreException;
    use framework\exceptions\CoreStrictException;
    use framework\exceptions\NotFoundException;
    use framework\exceptions\ResponseException;
    use framework\lang\String;
    use framework\logger\Logger;
    use framework\mvc\Controller;
    use framework\mvc\Response;
    use framework\mvc\results\Result;
    use framework\lang\FrameworkClassLoader;
    use framework\mvc\template\TemplateLoader;

abstract class Core {

    const type = __CLASS__;

    const VERSION = '0.2';
    
    /** @var string */
    public static $tempDir = 'tmp/';
    
    /**
     * @var array
     */
    private static $projects = array();
    
    /**
     * @var Project
     */
    public static $__project = null;


    static function init(){
        // TODO
        ini_set('display_errors', 'Off');
        error_reporting(E_ALL ^ E_NOTICE);
        set_include_path(ROOT);

        unset($_POST, $_GET, $_REQUEST);

        // register class loader
        require 'framework/lang/ClassLoader.php';
        $loader = new FrameworkClassLoader();
        $loader->register();
        
        // register system
        require 'framework/cache/InternalCache.php';

        self::_registerTriggers();
        self::_registerProjects();
        self::_registerCurrentProject();

        if ( APP_MODE_STRICT )
            set_error_handler(array(Core::type, 'errorHandler'));

        register_shutdown_function(array(Core::type, 'shutdown'), self::$__project);
    }

    private static function _registerTriggers(){
        SDK::registerTrigger('beforeRequest');
        SDK::registerTrigger('afterRequest');
        SDK::registerTrigger('finallyRequest');
        SDK::registerTrigger('registerTemplateEngine');
    }

    private static function _registerProjects(){
        $dirs = scandir(Project::getSrcDir());
        foreach ($dirs as $dir){
            if ($dir == '.' || $dir == '..') continue;
            self::$projects[ $dir ] = new Project( $dir );
        }
    }

    private static function _registerCurrentProject(){
        /** 
         * @var Project $project
         */
        foreach (self::$projects as $project){
            
            $url = $project->findCurrentPath();
            if ( $url ){
                self::$__project = $project;
                $project->setUriPath( $url );
                $project->register();
                return;
            }
        }
        
        throw new exceptions\CoreException("Can't find project for current request");
    }    
    
    public static function processRoute(){
        $project = Project::current();
        $router  = $project->router;
        
        $request = mvc\Request::current();
        $request->setBasePath( $project->getUriPath() );
                
        $router->route($request);

        try {
            if (!$router->action){
                throw new NotFoundException('404 Not found');
            }

            // TODO optimize ?
            $tmp = explode('.', $router->action);
            $controllerClass = implode('\\', array_slice($tmp, 0, -1));
            $actionMethod    = $tmp[ sizeof($tmp) - 1 ];

            /** @var $controller Controller */
            $controller = new $controllerClass;
            $controller->actionMethod = $actionMethod;
            $controller->routeArgs    = $router->args;
            try {
                $reflection = new \ReflectionMethod($controller, $actionMethod);
            } catch(\ReflectionException $e){
                throw new NotFoundException($e->getMessage());
            }

            $declClass = $reflection->getDeclaringClass();
            
            if ( $declClass->isAbstract() ){
                throw CoreException::formated('Can\'t use "%s.%s()" as action method', $controllerClass, $actionMethod);
            }

            SDK::trigger('beforeRequest', array($controller));
            
            $controller->callBefore();
            $router->invokeMethod($controller, $reflection);
            
        } catch (Result $result){
            $response = $result->getResponse();
        } catch (\Exception $e){
            
            if ( $controller ){
                try {
                    $responseErr = $controller->callException($e);
                } catch (Result $result){
                    $responseErr = $result->getResponse();
                }
            }
            
            if ( !$responseErr )
                throw $e;
            else {
                $response = $responseErr;
            }
        }
        
        if ( !$responseErr ){
            $controller->callAfter();
            SDK::trigger('afterRequest', array($controller));
        }
        
        if ( !$response ){
            throw new CoreException('Unknown type of controller result for response');
        }
        
        $response->send();
        $controller->callFinally();
        SDK::trigger('finallyRequest', array($controller));
    }

    private static function catchError($error, $logPath){

        $title = 'Fatal Error';

        switch($error['type']){
            case E_PARSE: $title = 'Parse Error'; break;
            case E_COMPILE_ERROR: $title = 'Compiler Error'; break;
            case E_CORE_ERROR: $title = 'Core Error'; break;
        }

        $file = str_replace('\\', '/', $error['file']);
        $error['line'] += CoreException::getErrorOffsetLine($file);
        $file = $error['file'] = CoreException::getErrorFile($file);
        $file = str_replace(str_replace('\\', '/', ROOT), '', $file);

        $source = null;
        if (IS_DEV && file_exists($error['file']) && is_readable($error['file']) ){
            $fp = fopen($error['file'], 'r');
            $n  = 1;
            $source = array();
            while($line = fgets($fp, 4096)){
                if ( $n > $error['line'] - 7 && $n < $error['line'] + 7 ){
                    $source[$n] = $line;
                }
                if ( $n > $error['line'] + 7 )
                    break;
                $n++;
            }
        }

        $hash = substr(md5(rand()), 12);
        if ($logPath){
            $can = true;
            if (!is_dir($logPath))
                $can = mkdir($logPath, 0777, true);

            if ($can){
                $fp = fopen($logPath . 'fail.log', 'a+');
                $time = date("[Y/M/d H:i:s]");
                    fwrite($fp,  "[$hash]$time" . PHP_EOL . "($title): $error[message]" . PHP_EOL);
                    fwrite($fp, $file . ' (' . $error['line'] . ')'.PHP_EOL . PHP_EOL);
                fclose($fp);
            }
        }
        include ROOT . 'framework/views/system/errors/fatal.phtml';
    }

    private static function catchAny(\Exception $e){
        if ( $e instanceof ResponseException ){
            $template = TemplateLoader::load('errors/' . $e->getStatus() . '.html');
            $template->putArgs(array('e' => $e));

            $response = new Response();
            $response->setStatus($e->getStatus());
            $response->setEntity($template);
            $response->send();
            return;
        }

        $stack = CoreException::findProjectStack($e);
        $info  = new \ReflectionClass($e);

        if ($stack){
            $file = str_replace('\\', '/', $stack['file']);
            $stack['line']        += CoreException::getErrorOffsetLine($file);
            $file = $stack['file'] = CoreException::getErrorFile($file);

            $file = str_replace(str_replace('\\', '/', ROOT), '', $file);

            $source = null;
            if (file_exists($stack['file']) && is_readable($stack['file']) ){
                $fp = fopen($stack['file'], 'r');
                $n  = 1;
                $source = array();
                while($line = fgets($fp, 4096)){
                    if ( $n > $stack['line'] - 7 && $n < $stack['line'] + 7 ){
                        $source[$n] = $line;
                    }
                    if ( $n > $stack['line'] + 7 )
                        break;
                    $n++;
                }
            }
        }

        $hash = substr(md5(rand()), 12);
        $template = TemplateLoader::load('system/errors/exception.html');
        $template->putArgs(array('exception' => $e,
            'stack' => $stack, 'info' => $info, 'hash' => $hash,
            'desc' => $e->getMessage(), 'file' => $file, 'source' => $source
        ));

        Logger::error('%s, in file `%s(%s)`, id: %s', $e->getMessage(), $file ? $file : "nofile", (int)$stack['line'], $hash);

        $response = new Response();
        $response->setStatus(500);
        $response->setEntity($template);
        $response->send();
    }

    public static function catchCoreException(CoreException $e){
        self::catchAny($e);
    }

    public static function catchErrorException(\ErrorException $e){
        self::catchAny($e);
    }

    public static function catchException(\Exception $e){
        self::catchAny($e);
    }

    public static function getFrameworkPath(){
        return ROOT . 'framework/';
    }

    public static function errorHandler($errno, $errstr, $errfile, $errline){
        if ( APP_MODE_STRICT ){
            $project = Project::current();
            $errfile = str_replace('\\', '/', $errfile);

            // only for project sources
            if (String::startsWith($errfile, $project->getPath())){
                if ( $errno === E_DEPRECATED
                    || $errno === E_USER_DEPRECATED
                    || $errno === E_WARNING ){
                    throw CoreStrictException::formated($errstr);
                }

                // ignore tmp dir
                if ( String::startsWith($errfile, $project->getPath() . 'tmp/') )
                    return false;

                if (String::startsWith($errstr, 'Undefined variable:')
                        || String::startsWith($errstr, 'Use of undefined constant')){
                    throw CoreStrictException::formated($errstr);
                }
            }
        }
    }
    
    public static function shutdown(Project $project){

        $error = error_get_last();
        if ($error){
            switch($error['type']){
                case E_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_PARSE:
                case E_USER_ERROR:
                case 4096: // Catchable fatal error
                {
                    self::catchError($error,
                        $project->config->getBoolean('logger.fatal.enable',true)
                            ? $project->getPath() . 'log/'
                            : false);

                    break;
                }
            }
        }

        ignore_user_abort(true);   
        
        ob_end_flush();
        ob_flush();
        flush();
    }


    /*** utils ***/
    public static function setTempDir($dir){
        if ( !is_dir($dir) ){
            if ( !mkdir($dir, 0777, true) ){
                throw new exceptions\CoreException('Unable to create temp directory `/tmp/`');
            }
            chmod($dir, 0777);
        }
        self::$tempDir = $dir;
    }
}


    abstract class StrictObject {

        public function __set($name, $value){
            throw CoreException::formated('Property `%s` not defined in `%s` class', $name, get_class($this));
        }

        public function __get($name){
            throw CoreException::formated('Property `%s` not defined in `%s` class', $name, get_class($this));
        }
    }

}

namespace {
    
    function dump($var){
        echo '<pre class="_dump">';
        print_r($var);
        echo '</pre>';
    }
}