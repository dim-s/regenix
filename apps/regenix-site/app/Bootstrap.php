<?php
namespace {

    use framework\AbstractBootstrap;
    use framework\cache\Cache;
    use framework\libs\I18n;
    use framework\mvc\Request;
    use framework\mvc\route\Router;
    use framework\mvc\template\BaseTemplate;

    class Bootstrap extends AbstractBootstrap {

        public function onStart(){

            $request = Request::current();
            if ($request->isBase('http://regenix.ru') || $request->isBase('http://localhost'))
                I18n::setLang('ru');
            else
                I18n::setLang('en');
        }

        public function onEnvironment(&$env){
            $request = Request::current();
            if ($request->isBase('http://regenix.ru'))
                $env = 'prod';
        }

        public function onTemplateRender(BaseTemplate $template){
            $links['Home']  = '/';

            $links['About']       = Router::path('Application.page', array('page' => 'about'));
            $links['Download']    = Router::path('Application.page', array('page' => 'download'));
            $links['Get Started'] = Router::path('Application.page', array('page' => 'getstarted'));
            $links['Documentation'] = Router::path('Application.page', array('page' => 'documentation'));
            $links['Community']   = Router::path('Application.page', array('page' => 'community'));

            $template->put('links', $links);
        }
    }
}