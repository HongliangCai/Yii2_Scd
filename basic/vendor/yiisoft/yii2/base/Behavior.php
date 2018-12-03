<?php
/**
 * @link http://www.yiiframework.com/
 * @copyright Copyright (c) 2008 Yii Software LLC
 * @license http://www.yiiframework.com/license/
 */

namespace yii\base;

/**
 * Behavior is the base class for all behavior classes.
 *
 * A behavior can be used to enhance the functionality of an existing component without modifying its code.
 * In particular, it can "inject" its own methods and properties into the component
 * and make them directly accessible via the component. It can also respond to the events triggered in the component
 * and thus intercept the normal code execution.
 *
 * For more details and usage information on Behavior, see the [guide article on behaviors](guide:concept-behaviors).
 *
 * @author Qiang Xue <qiang.xue@gmail.com>
 * @since 2.0
 */
class Behavior extends BaseObject
{
    /**
     * @var Component|null the owner of this behavior
     */
    // 指向行为本身所绑定的Component对象
    public $owner;


    /**
     * Declares event handlers for the [[owner]]'s events.
     *
     * Child classes may override this method to declare what PHP callbacks should
     * be attached to the events of the [[owner]] component.
     *
     * The callbacks will be attached to the [[owner]]'s events when the behavior is
     * attached to the owner; and they will be detached from the events when
     * the behavior is detached from the component.
     *
     * The callbacks can be any of the following:
     *
     * - method in this behavior: `'handleClick'`, equivalent to `[$this, 'handleClick']`
     * - object method: `[$object, 'handleClick']`
     * - static method: `['Page', 'handleClick']`
     * - anonymous function: `function ($event) { ... }`
     *
     * The following is an example:
     *
     * ```php
     * [
     *     Model::EVENT_BEFORE_VALIDATE => 'myBeforeValidate',
     *     Model::EVENT_AFTER_VALIDATE => 'myAfterValidate',
     * ]
     * ```
     *
     * @return array events (array keys) and the corresponding event handler methods (array values).
     */
    // Behavior 基类本身没用，主要是子类使用，重载这个函数返回一个数组表
    // 示行为所关联的事件
    public function events()
    {
        return [];
    }

    /**
     * Attaches the behavior object to the component.
     * The default implementation will set the [[owner]] property
     * and attach event handlers as declared in [[events]].
     * Make sure you call the parent implementation if you override this method.
     * @param Component $owner the component that this behavior is to be attached to.
     */
    // 绑定行为到 $owner // 这里 $owner 是某个 ActiveRecord
    public function attach($owner)
    {
        $this->owner = $owner;
        // 遍历 events() 所定义的数组
        foreach ($this->events() as $event => $handler) {
            // 调用 ActiveRecord::on 来绑定事件
            // 这里 $handler 为字符串 `evaluateAttributes`
            // 因此，相当于调用 on(BaseActiveRecord::EVENT_BEFORE_INSERT,
            // [$this, 'evaluateAttributes'])
            $owner->on($event, is_string($handler) ? [$this, $handler] : $handler);
        }
    }

    /**
     * Detaches the behavior object from the component.
     * The default implementation will unset the [[owner]] property
     * and detach event handlers declared in [[events]].
     * Make sure you call the parent implementation if you override this method.
     */
    // 解除绑定
    public function detach()
    {
        // 这得是个名花有主的行为才有解除一说
        if ($this->owner) {
            // 遍历行为定义的事件，一一解除
            foreach ($this->events() as $event => $handler) {
                $this->owner->off($event, is_string($handler) ? [$this, $handler] : $handler);
            }
            $this->owner = null;
        }
    }
}
