Welcome to Regenix framework
============================

Regenix easy to use and learn MVC framework.
Regenix framework impacted on such projects as the [Play! Framework](http://playframework.com/),
Ruby on Rails and Django.

[![Build Status](https://travis-ci.org/dim-s/regenix.png?branch=dev)](https://travis-ci.org/dim-s/regenix)

![regenix](http://develstudio.ru/upload/medialibrary/cf8/cf88db498096a1eba21c75f7910a4ef4.png)

Features
--------
* MVC Architecture
* Route for url
* Multiple projects on a single core 
* RESTfull services
* Easy form binding
* Dependency injection
* Validation based on tests
* Quick and concise syntax template engine
* Lazy class loading
* Session, Flash, etc.
* More utils classes
* Powerful modules, easy to write modules

Current Versions
----------------
* version 0.1 - start regenix project

Requires
--------

* PHP 5.3 or greater
* Apache, Nginx or another server
* Mod_rewrite enable


Getting started
---------------

### Installation

Copy all source from git, create the directory project in `apps/<project_name>/`.
Project `project1` already exists in regenix source. 

### Project structure

* conf/ - configuration directory
 * `conf/application.conf` - general config
 * `conf/route` - url routing config
* app/controllers/ - controller directory
* app/models/ - models directory, ORM
* app/views/ - directory for search templates
* app/tests/ - unit and other tests
* assets - assets static directory for images, js, css, etc.

### First controller

1. Create `Application.php` in `app/controllers/`
2. Write `Application` class in `controllers` namespace, inherited from `framework\mvc\Controller` class
3. Define controller public method `index`


```
<? 
/* /apps/project1/controllers/Application.php */

namespace controllers

use framework\mvc\Controller;

class Application extends Controller {

    public function index(){
         /* add named variable to template */
         $this->put('var', 'Hello world');

         /* Render template views/Application/index.html and exit */
         $this->render();

         /* after, the code will not work ...
          ... */
    }

    public function json(){
        /* render json answer */
        $result = array('status' => 'ok', 'error' => null);
        $this->renderJSON($result);
    }

    public function page($id){
        /* using models & service */
        $service = Page::getService();
        $page    = $service->findById($id);

        $this->put('page', $page);
        $this->render();
    }
}
```

and in route config:

```
GET     /                   Application.index
POST    /json               Application.json
*       /page/{id:int}/     Application.page
```