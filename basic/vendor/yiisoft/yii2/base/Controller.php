<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\base;

use Yii;

/**
 * Controller is the base class for classes containing controller logic.
 *
 * For more details and usage information on Controller, see the [guide article on controllers](guide:structure-controllers).
 *
 * @property Module[] $modules All ancestor modules that this controller is located within. This property is
 * read-only.
 * @property string $route The route (module ID, controller ID and action ID) of the current request. This
 * property is read-only.
 * @property string $uniqueId The controller ID that is prefixed with the module ID (if any). This property is
 * read-only.
 * @property View|\yii\web\View $view The view object that can be used to render views or view files.
 * @property string $viewPath The directory containing the view files for this controller.
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Controller extends Component implements ViewContextInterface
{
    /**
     * @event ActionEvent an event raised right before executing a controller action.
     * You may set [[ActionEvent::isValid]] to be false to cancel the action execution.
     */
    //在执行beforeAction方法时触发的事件，
    //如果对事件的isValid属性设置为false，将取消action的执行
    const EVENT_BEFORE_ACTION = 'beforeAction';
    /**
     * @event ActionEvent an event raised right after executing a controller action.
     */
    //在执行afterAction方法是触发的事件
    const EVENT_AFTER_ACTION = 'afterAction';

    /**
     * @var string the ID of this controller.
     */
    //控制器id
    public $id;
    /**
     * @var Module the module that this controller belongs to.
     */
    //所属模块
    public $module;
    /**
     * @var string the ID of the action that is used when the action ID is not specified
     * in the request. Defaults to 'index'.
     */
    //控制器中默认动作
    public $defaultAction = 'index';
    /**
     * @var null|string|false the name of the layout to be applied to this controller's views.
     * This property mainly affects the behavior of [[render()]].
     * Defaults to null, meaning the actual layout value should inherit that from [[module]]'s layout value.
     * If false, no layout will be applied.
     */
    //布局文件，如果设置为false，则不使用布局文件
    public $layout;
    /**
     * @var Action the action that is currently being executed. This property will be set
     * by [[run()]] when it is called by [[Application]] to run an action.
     */
    //当前下面执行的action，可在事件中根据这个action来执行不同的操作
    public $action;

    /**
     * @var View the view object that can be used to render views or view files.
     */
    //视图对象
    private $_view;
    /**
     * @var string the root directory that contains view files for this controller.
     */
    private $_viewPath;


    /**
     * @param string $id the ID of this controller.
     * @param Module $module the module that this controller belongs to.
     * @param array $config name-value pairs that will be used to initialize the object properties.
     */
    public function __construct($id, $module, $config = [])
    {
        $this->id = $id;
        $this->module = $module;
        parent::__construct($config);
    }

    /**
     * Declares external actions for the controller.
     *
     * This method is meant to be overwritten to declare external actions for the controller.
     * It should return an array, with array keys being action IDs, and array values the corresponding
     * action class names or action configuration arrays. For example,
     *
     * ```php
     * return [
     *     'action1' => 'app\components\Action1',
     *     'action2' => [
     *         'class' => 'app\components\Action2',
     *         'property1' => 'value1',
     *         'property2' => 'value2',
     *     ],
     * ];
     * ```
     *
     * [[\Yii::createObject()]] will be used later to create the requested action
     * using the configuration provided here.
     */
    public function actions()
    {
        return [];
    }

    /**
     * Runs an action within this controller with the specified action ID and parameters.
     * If the action ID is empty, the method will use [[defaultAction]].
     * @param string $id the ID of the action to be executed.
     * @param array $params the parameters (name-value pairs) to be passed to the action.
     * @return mixed the result of the action.
     * @throws InvalidRouteException if the requested action ID cannot be resolved into an action successfully.
     * @see createAction()
     */
    public function runAction($id, $params = [])
    {
        //创建action实例
        $action = $this->createAction($id);
        if ($action === null) {
            throw new InvalidRouteException('Unable to resolve the request: ' . $this->getUniqueId() . '/' . $id);
        }

        Yii::debug('Route to run: ' . $action->getUniqueId(), __METHOD__);

        if (Yii::$app->requestedAction === null) {
            Yii::$app->requestedAction = $action;
        }

        $oldAction = $this->action;
        $this->action = $action;
        //用来保存当前控制器的所有父模块，顺序为由子模块到父模块
        $modules = [];
        $runAction = true;

        //返回的modules包括该controller当前所在的module，以及该module的所有祖先module（递归直至没有祖先module）
        //然后从最初的祖先module开始，依次执行“模块级”的beforeActio()
        //如果有beforeAction()没有返回true， 那么会中断后续的执行
        // call beforeAction on modules

        /*
         * 获取当前控制器的所以的模块，并执行每个模块的beforeAction来检查当前的action是否可以执行，
         * 注意：getModules返回的数组顺序为：从父模块到子模块，
         * 所以在执行beforeAction的时候，先检查最外层的父模块，然后检查子模块。
         * 
         * 然而在执行afterAction的时候，顺序就反过来了，先执行子模块，最后执行父模块。
         * 
         */
        // call beforeAction on modules
        foreach ($this->getModules() as $module) {
            if ($module->beforeAction($action)) {
                array_unshift($modules, $module);
            } else {
                $runAction = false;
                break;
            }
        }

        $result = null;
        /*
        * 再判断当前控制器中是beforeAction，
        * 最后由生成的action对象来执行runWithParams方法
        * 
        * 执行完后，再执行afterAction方法
        */
        //执行当前控制器的beforeAction，通过后再最终执行action
        //（如果前面“模块级beforeAction”没有全部返回true，则这里不会执行）
        if ($runAction && $this->beforeAction($action)) {
            // run the action
            //代码位置：yii\base\InlineAction::runWithParams()
            $result = $action->runWithParams($params);
            //执行当前Controller的afterAction
            $result = $this->afterAction($action, $result);

            //从当前模块开始，执行afterAction，直至所有祖先的afterAction
            // call afterAction on modules
            foreach ($modules as $module) {
                /* @var $module Module */
                $result = $module->afterAction($action, $result);
            }
        }

        if ($oldAction !== null) {
            $this->action = $oldAction;
        }
        //如果有beforeAction没有通过，那么会返回null
        return $result;
    }

    /**
     * Runs a request specified in terms of a route.
     * The route can be either an ID of an action within this controller or a complete route consisting
     * of module IDs, controller ID and action ID. If the route starts with a slash '/', the parsing of
     * the route will start from the application; otherwise, it will start from the parent module of this controller.
     * @param string $route the route to be handled, e.g., 'view', 'comment/view', '/admin/comment/view'.
     * @param array $params the parameters to be passed to the action.
     * @return mixed the result of the action.
     * @see runAction()
     */
    public function run($route, $params = [])
    {
        $pos = strpos($route, '/');
        if ($pos === false) {
            return $this->runAction($route, $params);
        } elseif ($pos > 0) {
            return $this->module->runAction($route, $params);
        }

        return Yii::$app->runAction(ltrim($route, '/'), $params);
    }

    /**
     * Binds the parameters to the action.
     * This method is invoked by [[Action]] when it begins to run with the given parameters.
     * @param Action $action the action to be bound with parameters.
     * @param array $params the parameters to be bound to the action.
     * @return array the valid parameters that the action can run with.
     */
    public function bindActionParams($action, $params)
    {
        return [];
    }

    /**
     * Creates an action based on the given action ID.
     * The method first checks if the action ID has been declared in [[actions()]]. If so,
     * it will use the configuration declared there to create the action object.
     * If not, it will look for a controller method whose name is in the format of `actionXyz`
     * where `Xyz` stands for the action ID. If found, an [[InlineAction]] representing that
     * method will be created and returned.
     * @param string $id the action ID.
     * @return Action|null the newly created action instance. Null if the ID doesn't resolve into any action.
     */
    public function createAction($id)
    {
        //默认action,默认值为：index
        if ($id === '') {
            $id = $this->defaultAction;
        }
        //独立action（Standalone Actions ）
        $actionMap = $this->actions();
        if (isset($actionMap[$id])) { //如果在actions方法中指定了独立的动作，则直接使用此动作。
            //返回一个action实例，通常是yii\base\Action的子类
            return Yii::createObject($actionMap[$id], [$id, $this]);
        } elseif (preg_match('/^[a-z0-9\\-_]+$/', $id) && strpos($id, '--') === false && trim($id, '-') === $id) {
            $methodName = 'action' . str_replace(' ', '', ucwords(implode(' ', explode('-', $id))));
            if (method_exists($this, $methodName)) {
                /*
                * 如果当前控制器中存在这个actionXXX方法，
                * 再通过反射生成方法，再次检查一遍，最后生成InlineAction
                */
                $method = new \ReflectionMethod($this, $methodName);
                if ($method->isPublic() && $method->getName() === $methodName) {
                    //InlineAction封装了将要执行的action的相关信息，该类继承自yii\base\Action
                    return new InlineAction($id, $this, $methodName);
                }
            }
        }

        return null;
    }

    /**
     * This method is invoked right before an action is executed.
     *
     * The method will trigger the [[EVENT_BEFORE_ACTION]] event. The return value of the method
     * will determine whether the action should continue to run.
     *
     * In case the action should not run, the request should be handled inside of the `beforeAction` code
     * by either providing the necessary output or redirecting the request. Otherwise the response will be empty.
     *
     * If you override this method, your code should look like the following:
     *
     * ```php
     * public function beforeAction($action)
     * {
     *     // your custom code here, if you want the code to run before action filters,
     *     // which are triggered on the [[EVENT_BEFORE_ACTION]] event, e.g. PageCache or AccessControl
     *
     *     if (!parent::beforeAction($action)) {
     *         return false;
     *     }
     *
     *     // other custom code here
     *
     *     return true; // or false to not run the action
     * }
     * ```
     *
     * @param Action $action the action to be executed.
     * @return bool whether the action should continue to run.
     */
    //在具体的动作执行之前会先执行beforeAction，如果返回false,则动作将不会被执行，
//后面的afterAction也不会执行（但父模块跌afterAction会执行）
    public function beforeAction($action)
    {
        $event = new ActionEvent($action);
        $this->trigger(self::EVENT_BEFORE_ACTION, $event);
        return $event->isValid;
    }

    /**
     * This method is invoked right after an action is executed.
     *
     * The method will trigger the [[EVENT_AFTER_ACTION]] event. The return value of the method
     * will be used as the action return value.
     *
     * If you override this method, your code should look like the following:
     *
     * ```php
     * public function afterAction($action, $result)
     * {
     *     $result = parent::afterAction($action, $result);
     *     // your custom code here
     *     return $result;
     * }
     * ```
     *
     * @param Action $action the action just executed.
     * @param mixed $result the action return result.
     * @return mixed the processed action result.
     */
    //当前动作执行之后，执行afterAction
    public function afterAction($action, $result)
    {
        $event = new ActionEvent($action);
        $event->result = $result;
        $this->trigger(self::EVENT_AFTER_ACTION, $event);
        return $event->result;
    }

    /**
     * Returns all ancestor modules of this controller.
     * The first module in the array is the outermost one (i.e., the application instance),
     * while the last is the innermost one.
     * @return Module[] all ancestor modules that this controller is located within.
     */
    //获取当前控制器所有的父模块
    public function getModules()
    {
        $modules = [$this->module];
        $module = $this->module;
        while ($module->module !== null) {
            //由这里可知，返回的数组顺序为从父模块到子模块
            array_unshift($modules, $module->module);
            $module = $module->module;
        }

        return $modules;
    }

    /**
     * Returns the unique ID of the controller.
     * @return string the controller ID that is prefixed with the module ID (if any).
     */
    //返回控制器id
    public function getUniqueId()
    {
        //如果当前所属模块为application，则就为该id,否则要前面要加上模块id
        return $this->module instanceof Application ? $this->id : $this->module->getUniqueId() . '/' . $this->id;
    }

    /**
     * Returns the route of the current request.
     * @return string the route (module ID, controller ID and action ID) of the current request.
     */
    //获取路由信息
    public function getRoute()
    {
        return $this->action !== null ? $this->action->getUniqueId() : $this->getUniqueId();
    }

    /**
     * Renders a view and applies layout if available.
     *
     * The view to be rendered can be specified in one of the following formats:
     *
     * - [path alias](guide:concept-aliases) (e.g. "@app/views/site/index");
     * - absolute path within application (e.g. "//site/index"): the view name starts with double slashes.
     *   The actual view file will be looked for under the [[Application::viewPath|view path]] of the application.
     * - absolute path within module (e.g. "/site/index"): the view name starts with a single slash.
     *   The actual view file will be looked for under the [[Module::viewPath|view path]] of [[module]].
     * - relative path (e.g. "index"): the actual view file will be looked for under [[viewPath]].
     *
     * To determine which layout should be applied, the following two steps are conducted:
     *
     * 1. In the first step, it determines the layout name and the context module:
     *
     * - If [[layout]] is specified as a string, use it as the layout name and [[module]] as the context module;
     * - If [[layout]] is null, search through all ancestor modules of this controller and find the first
     *   module whose [[Module::layout|layout]] is not null. The layout and the corresponding module
     *   are used as the layout name and the context module, respectively. If such a module is not found
     *   or the corresponding layout is not a string, it will return false, meaning no applicable layout.
     *
     * 2. In the second step, it determines the actual layout file according to the previously found layout name
     *    and context module. The layout name can be:
     *
     * - a [path alias](guide:concept-aliases) (e.g. "@app/views/layouts/main");
     * - an absolute path (e.g. "/main"): the layout name starts with a slash. The actual layout file will be
     *   looked for under the [[Application::layoutPath|layout path]] of the application;
     * - a relative path (e.g. "main"): the actual layout file will be looked for under the
     *   [[Module::layoutPath|layout path]] of the context module.
     *
     * If the layout name does not contain a file extension, it will use the default one `.php`.
     *
     * @param string $view the view name.
     * @param array $params the parameters (name-value pairs) that should be made available in the view.
     * These parameters will not be available in the layout.
     * @return string the rendering result.
     * @throws InvalidArgumentException if the view file or the layout file does not exist.
     */
    public function render($view, $params = [])
    {   //由view对象渲染视图文件
        $content = $this->getView()->render($view, $params, $this);
        return $this->renderContent($content);
    }

    /**
     * Renders a static string by applying a layout.
     * @param string $content the static string being rendered
     * @return string the rendering result of the layout with the given static string as the `$content` variable.
     * If the layout is disabled, the string will be returned back.
     * @since 2.0.1
     */
    public function renderContent($content)
    {   //查找布局文件
        $layoutFile = $this->findLayoutFile($this->getView());
        if ($layoutFile !== false) {
            //由view对象渲染布局文件，
            //并把上面的视图结果作为content变量传递到布局中，所以布局中才会有$content变量来表示
            return $this->getView()->renderFile($layoutFile, ['content' => $content], $this);
        }

        return $content;
    }

    /**
     * Renders a view without applying layout.
     * This method differs from [[render()]] in that it does not apply any layout.
     * @param string $view the view name. Please refer to [[render()]] on how to specify a view name.
     * @param array $params the parameters (name-value pairs) that should be made available in the view.
     * @return string the rendering result.
     * @throws InvalidArgumentException if the view file does not exist.
     */
    //这个只渲染视图文件，不会应用布局
    public function renderPartial($view, $params = [])
    {
        return $this->getView()->render($view, $params, $this);
    }

    /**
     * Renders a view file.
     * @param string $file the view file to be rendered. This can be either a file path or a [path alias](guide:concept-aliases).
     * @param array $params the parameters (name-value pairs) that should be made available in the view.
     * @return string the rendering result.
     * @throws InvalidArgumentException if the view file does not exist.
     */
    //这个就是用来渲染一个文件，$file为文件实路径或别名路径
    public function renderFile($file, $params = [])
    {
        return $this->getView()->renderFile($file, $params, $this);
    }

    /**
     * Returns the view object that can be used to render views or view files.
     * The [[render()]], [[renderPartial()]] and [[renderFile()]] methods will use
     * this view object to implement the actual view rendering.
     * If not set, it will default to the "view" application component.
     * @return View|\yii\web\View the view object that can be used to render views or view files.
     */
    public function getView()
    {
        if ($this->_view === null) {
            $this->_view = Yii::$app->getView();
        }

        return $this->_view;
    }

    /**
     * Sets the view object to be used by this controller.
     * @param View|\yii\web\View $view the view object that can be used to render views or view files.
     */
    public function setView($view)
    {
        $this->_view = $view;
    }

    /**
     * Returns the directory containing view files for this controller.
     * The default implementation returns the directory named as controller [[id]] under the [[module]]'s
     * [[viewPath]] directory.
     * @return string the directory containing the view files for this controller.
     */
    //获取这个控制器对应的view的文件路径，如@app/views/site/xxxx.php
    public function getViewPath()
    {
        if ($this->_viewPath === null) {
            $this->_viewPath = $this->module->getViewPath() . DIRECTORY_SEPARATOR . $this->id;
        }

        return $this->_viewPath;
    }

    /**
     * Sets the directory that contains the view files.
     * @param string $path the root directory of view files.
     * @throws InvalidArgumentException if the directory is invalid
     * @since 2.0.7
     */
    public function setViewPath($path)
    {
        $this->_viewPath = Yii::getAlias($path);
    }

    /**
     * Finds the applicable layout file.
     * @param View $view the view object to render the layout file.
     * @return string|bool the layout file path, or false if layout is not needed.
     * Please refer to [[render()]] on how to specify this parameter.
     * @throws InvalidArgumentException if an invalid path alias is used to specify the layout.
     */
    public function findLayoutFile($view)
    {
        $module = $this->module;
        //如果当前控制器设置了布局文件，则直接使用所设置的布局文件
        if (is_string($this->layout)) {
            $layout = $this->layout;
        } elseif ($this->layout === null) {
            //如果没有设置布局文件，则查找所有的父模块的布局文件。
            while ($module !== null && $module->layout === null) {
                $module = $module->module;
            }
            if ($module !== null && is_string($module->layout)) {
                $layout = $module->layout;
            }
        }
        //如果没有设置布局文件，返回false
        if (!isset($layout)) {
            return false;
        }
        /*
         * 布局文件有三种路径写法
         * 1、以“@”开头，这种会在别名路径中查找布局文件
         * 2、以“/”开头，这个会从应用程序的布局文件目录下面查找布局文件
         * 3、其它情况，   这个会从当前模块的布局文件目录下查查找布局文件
         */

        if (strncmp($layout, '@', 1) === 0) {
            $file = Yii::getAlias($layout);
        } elseif (strncmp($layout, '/', 1) === 0) {
            $file = Yii::$app->getLayoutPath() . DIRECTORY_SEPARATOR . substr($layout, 1);
        } else {
            $file = $module->getLayoutPath() . DIRECTORY_SEPARATOR . $layout;
        }
        //如果布局文件有文件扩展名，返回
        if (pathinfo($file, PATHINFO_EXTENSION) !== '') {
            return $file;
        }
        //加上默认的文件扩展名。
        $path = $file . '.' . $view->defaultExtension;
        //如果文件不存在，并且，默认的文件扩展名也不是php，则给加上php作为扩展名。
        if ($view->defaultExtension !== 'php' && !is_file($path)) {
            $path = $file . '.php';
        }

        return $path;
    }
}
