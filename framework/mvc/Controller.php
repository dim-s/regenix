<?php
namespace regenix\mvc;

use regenix\core\Regenix;
use regenix\exceptions\HttpException;
use regenix\lang\DI;
use regenix\lang\IClassInitialization;
use regenix\lang\StrictObject;
use regenix\lang\CoreException;
use regenix\lang\File;
use regenix\mvc\controllers\EmptyController;
use regenix\mvc\http\Cookie;
use regenix\mvc\http\Request;
use regenix\mvc\http\RequestBody;
use regenix\mvc\http\RequestQuery;
use regenix\mvc\http\Response;
use regenix\mvc\http\session\Flash;
use regenix\mvc\http\session\Session;
use regenix\mvc\providers\FileResponse;
use regenix\mvc\providers\ResponseFileProvider;
use regenix\mvc\providers\ResponseProvider;
use regenix\mvc\route\Router;
use regenix\mvc\template\TemplateLoader;
use regenix\lang\String;
use regenix\validation\Validator;

abstract class Controller extends StrictObject
    implements IClassInitialization {

    const type = __CLASS__;

    private $__data = array(
        'request' => null,
        'session' => null,
        'flash'   => null,
        'cookie'  => null,
        'query'   => null,
        'body'    => null
    );

    /** @var Response */
    public $response;

    /** @var Request */
    public $request;

    /** @var Session */
    public $session;

    /** @var Flash */
    public $flash;

    /** @var Cookie */
    public $cookie;
    
    /** @var RequestQuery */
    public $query;

    /** @var RequestBody */
    public $body;

    /**
     * method name of invoke in request
     * @var string
     */
    public $actionMethod;

    /**
     * @var \ReflectionMethod
     */
    public $actionMethodReflection;

    /**
     * @var Annotations
     */
    public $actionMethodAnnotations;

    /**
     * template arguments
     * @var array[string, any]
     **/
    private $renderArgs = array();

    /**
     * get current route arguments
     * @var array[string, any]
     */
    public $routeArgs = array();

    /** @var Validator[] */
    protected $validators = array();

    /**
     * @var bool
     */
    private $useSession = true;

    /**
     * @var Controller
     */
    private static $current;


    public function __construct() {
        $this->response = new Response();

        unset($this->body);
        unset($this->request);
        unset($this->cookie);
        unset($this->session);
        unset($this->flash);
        unset($this->query);
        unset($this->actionMethodAnnotations);
    }

    public function __get($name){
        if ($this->__data[$name])
            return $this->__data[$name];

        $value = null;
        switch($name){
            case 'body':    $value = DI::getSingleton(RequestBody::type); break;
            case 'request': $value = DI::getSingleton(Request::type); break;
            case 'cookie':  $value = DI::getSingleton(Cookie::type); break;
            case 'session': $value = DI::getSingleton(Session::type); break;
            case 'flash':   $value = DI::getSingleton(Flash::type); break;
            case 'query':   $value = DI::getSingleton(RequestQuery::type); break;
            case 'actionMethodAnnotations':
                $value = Annotations::getMethodAnnotation($this->actionMethodReflection);
                if ($value == null)
                    $value = Annotations::getEmpty();
                break;

            default: {
                return parent::__get($name);
            }
        }

        return $this->__data[$name] = $value;
    }

    protected function onBefore(){}
    protected function onAfter(){}
    protected function onFinally(){}
    protected function onReturn($result){}

    protected function onException(\Exception $e){}
    protected function onHttpException(HttpException $e){}

    /**
     * @param $params
     */
    protected function onBindParams(&$params){}
    
    public function callBefore(){
        $this->onBefore();
    }
    
    public function callAfter(){
        $this->onAfter();
    }
    
    public function callFinally(){
        $this->onFinally();
        if ($this->useSession){
            $this->flash->touchAll();
        }
    }

    public function callReturn($result){
        $this->onReturn($result);
    }

    public function callBindParams(&$params){
        $this->onBindParams($params);
    }

    /**
     * set use session, if true use session and flash features
     * default: true
     * @param bool $value
     */
    protected function setUseSession($value){
        $this->useSession = (bool)$value;
    }
    
    final public function callException(\Exception $e){
        $this->onException($e);
    }

    final public function callHttpException(HttpException $e){
        $this->onHttpException($e);
    }

    /**
     * @param Validator $validator
     * @param bool $method
     * @return $this
     */
    protected function validate(Validator $validator, $method = false){
        $validator->validate($method);
        $this->validators[] = $validator;
        return $this;
    }

    /**
     * @return bool
     */
    protected function hasErrors(){
        foreach($this->validators as $validator){
            if ($validator->hasErrors())
                return true;
        }
        return false;
    }

    /**
     * put a variable for template
     * @param string $varName
     * @param mixed $value
     * @return Controller
     */
    public function put($varName, $value){
        $this->renderArgs[ $varName ] = $value;
        return $this;
    }

    /**
     * put a variables for template
     * @param array $vars
     * @return Controller
     */
    public function putAll(array $vars){
        foreach ($vars as $key => $value) {
            $this->put($key, $value);
        }
        return $this;
    }

    public function send(){
        throw new Result($this->response);
    }

    private function __redirectUrl($url, $permanent = false){
        $this->response
            ->setStatus($permanent ? 301 : 302)
            ->setHeader('Location', $url);
        $this->send();
    }

    /**
     * @param $url
     * @param bool $permanent
     */
    public function redirectUrl($url, $permanent = false){
        if (String::startsWith($url, "/")){
            $app = Regenix::app();
            $url = $app->getUriPath($url);
        }
        $this->__redirectUrl($url, $permanent);
    }

    /**
     * @param $action
     * @param array $args
     * @param bool $permanent
     * @throws \regenix\lang\CoreException
     */
    public function redirect($action, array $args = array(), $permanent = false){
        if (strpos($action, '.') === false)
            $action = '.' . get_class($this) . '.' . $action;

        $url = Router::path($action, $args, 'GET');
        if ($url === null)
            throw new CoreException('Can`t reverse url for action "%s(%s)"',
                $action, implode(', ', array_keys($args)));

        $this->saveErrors();

        $this->__redirectUrl($url, $permanent);
    }

    /**
     * redirect to current open url
     * @param array $args
     * @param bool $permanent
     */
    public function refresh(array $args = array(), $permanent = false){
        $this->redirect($this->actionMethod, $args, $permanent);
    }

    /**
     * redirect to current open url
     * @param array $args
     * @param bool $permanent
     */
    public function refreshUrl(array $args = array(), $permanent = false){
        $this->redirectUrl($this->request->getUri(), $args, $permanent);
    }

    /**
     * switch template engine
     * @param string $templateEngine
     */
    public function setTemplateEngine($templateEngine){
        TemplateLoader::switchEngine($templateEngine);
    }


    /**
     * Work out the default template to load for the invoked action.
     * E.g. "controllers\Pages\index" returns "views/Pages/index.html".
     */
    public function template($template = false){
        if (!$template){
            $class = $this->actionMethodReflection->getDeclaringClass()->getName();
            $controller = str_replace('\\', '/', $class);

            if ( String::startsWith($controller, 'controllers/') )
                $controller = substr($controller, 12);

            $template   = $controller . '/' . $this->actionMethod;
        }
        return str_replace('\\', '/', $template);
    }

    protected function saveErrors() {
        $errors = array();
        foreach($this->validators as $validator){
            $errors = array_merge($errors, $validator->getErrors());
        }

        if ($errors) {
            $this->put("errors", $errors);
            $this->flash->put("errors", $errors);
        }
    }

    /**
     * Render the corresponding template
     * render template by action method name or template name
     * @param bool|object|string $template
     * @param array $args
     */
    public function render($template = false, array $args = null){
        if (is_object($template)){
            $this->response->setEntity($template);
            $this->send();
        } else
            $this->renderTemplate($template, $args);
    }

    /**
     * Render a specific template.
     * @param $template
     * @param array $args
     */
    public function renderTemplate($template, array $args = null){
        if ( $args )
            $this->putAll($args);

        $template = $this->template($template);

        $this->put("flash", $this->flash);
        $this->put("session", $this->session);
        $this->put("request", $this->request);

        $this->saveErrors();

        $template = template\TemplateLoader::load($template);
        $template->putArgs( $this->renderArgs );

        $this->response->setEntity($template);
        $this->send();
    }

    /**
     * @param $template string
     * @return bool
     */
    public function templateExists($template){
        $template = TemplateLoader::load($template, false);
        return !!$template;
    }

    public function renderText($text){
        $this->response->setEntity( $text );
        $this->send();
    }
    
    public function renderHtml($html){
        $this->response
                ->setContentType('text/html')
                ->setEntity($html);
        
        $this->send();
    }

    public function renderJson($object){
        $this->response
                ->setContentType('application/json')
                ->setEntity( json_encode($object) );
        
        $error = json_last_error();
        if ( $error > 0 ){
            throw new CoreException('Error json encode, ' . $error);
        }
        
        $this->send();
    }

    public function renderJsonp($object){
        $callback = $this->query->get('jsonp');
        if (!$callback)
            $callback = $this->query->get('callback');
        if (!$callback)
            $callback = 'callback' . rand(0, 99999);

        $this->response
            ->setContentType('application/javascript')
            ->setEntity( $callback . '(' . json_encode($object) . ')' );

        $error = json_last_error();
        if ( $error > 0 ){
            throw new CoreException('Error json encode, ' . $error);
        }

        $this->send();
    }
    
    public function renderXml($xml){
        if ( $xml instanceof \SimpleXMLElement ){
            /** @var \SimpleXMLElement */
            /// TODO
        }
    }

    /**
     * @param File|string $file
     * @param bool $attach
     */
    public function renderFile($file, $attach = true){
        $this->setUseSession(false);
        // TODO optimize ?
        ResponseProvider::register(ResponseFileProvider::type);

        $this->response->setEntity(new FileResponse($file, $attach));
        $this->send();
    }

    /**
     * render print_r var if dev
     * @param $var
     */
    public function renderVar($var){
        $this->renderHtml('<pre>' . print_r($var, true) . '</pre>');
    }

    /**
     * render var_dump var if dev
     * @param $var
     */
    public function renderDump($var){
        ob_start();
        var_dump($var);
        $str = ob_get_contents();
        ob_end_clean();

        $this->renderHtml($str);
    }

    public function ok(){
        $this->response->setStatus(200);
        $this->send();
    }

    /**
     * @param string $message
     */
    public function todo($message = ''){
        $this->render('system/todo.html', array('message' => $message));
    }

    /**
     * @param string $message
     * @throws \regenix\exceptions\HttpException
     */
    public function forbidden($message = ''){
        throw new HttpException(HttpException::E_FORBIDDEN, $message);
    }

    /**
     * @param string $message
     * @throws \regenix\exceptions\HttpException
     */
    public function notFound($message = ''){
        throw new HttpException(HttpException::E_NOT_FOUND, $message);
    }

    /**
     * Can use several what, notFoundIfEmpty(arg1, arg2, arg3 ...)
     * @param mixed $whats..
     */
    public function notFoundIfEmpty($whats){
        $args = func_get_args();
        foreach($args as $arg){
            if (empty($arg))
                $this->notFound();
        }
    }

    /**
     * If not ajax request to 404 exception
     */
    public function forAjaxOnly(){
        if (!$this->request->isAjax())
            $this->notFound('For ajax only');
    }

    /**
     *
     * @return Controller NotNull
     */
    public static function __current(){
        if (!self::$current)
            self::$current = new EmptyController();

        return self::$current;
    }

    public static function initialize() {
        DI::bindTo(Controller::type, function(){
            // get current
            return Controller::__current();
        });
    }
}