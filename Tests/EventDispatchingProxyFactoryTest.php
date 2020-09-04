<?php
/**
 * This file is part of the PsuwProxyFactory package.
 *
 * @copyright Copyright (c) 2019 Paweł Suwiński
 * @author Paweł Suwiński <psuw@wp.pl>
 * @license MIT
 */

namespace Psuw\ProxyFactory\Tests;

use Psuw\ProxyFactory\EventDispatchingProxyFactory;
use Symfony\Component\EventDispatcher\Event;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\DependencyInjection\Container;
use ProxyManager\Configuration;
use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use ProxyManager\Factory\AccessInterceptorScopeLocalizerFactory;

/**
 * EventDispatchingProxyFactoryTest
 *
 * @preserveGlobalState disabled
 * @package PsuwProxyFactory
 * @copyright Copyright (c) 2019, Paweł Suwiński
 * @author Paweł Suwiński <psuw@wp.pl> 
 * @license MIT
 */
class EventDispatchingProxyFactoryTest extends \PHPUnit\Framework\TestCase
{
    /**
     * useValueHolder 
     * 
     * @var bool
     */
    protected $useValueHolder = true;

    /**
     * __call 
     * 
     * @param string $name 
     * @param array $arguments 
     * @return void
     */
    public function __call($name, array $arguments) 
    {
        return __FUNCTION__;
    }


    /**
     * testObjectExpectedException 
     *
     * @expectedException \InvalidArgumentException
     * @expectedExceptionMessageRegExp #Object expected!#
     * @return void
     */
    public function testObjectExpectedException()
    {
        (new EventDispatchingProxyFactory(new EventDispatcher()))
            ->createProxy(array(), array('firstMethod'));
    }

    /**
     * testBadMethodCallException 
     *
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessageRegExp #Method .*thirdMethod" not exists!#
     * @return void
     */
    public function testBadMethodCallException()
    {
        $this->getProxy(
            ['firstMethod', 'thirdMethod'],
            null,
            $this->getTestMock(EventDispatchingProxyFactory::class)
        )->thirdMethod();
    }

    /**
     * testBadMethodCallExceptionOnNotIntercepted 
     *
     * @expectedException \BadMethodCallException
     * @expectedExceptionMessageRegExp #Method .*fourthMethod" not exists!#
     * @return void
     */
    public function testBadMethodCallExceptionOnNotIntercepted()
    {
        $proxy = $this->getProxy(
            array('firstMethod', 'thirdMethod'),
            $this->getDispatcher(array(
                'pre_third_method' => array('return_value' => 'thirdMethod')
            )),
            $this->getTestMock(EventDispatchingProxyFactory::class)
        );

        /**
         * event dispatcher level method handled 
         */
        $this->assertEquals('thirdMethod', $proxy->thirdMethod());
        
        /**
         * method not configured as intercepted one 
         */
        $proxy->fourthMethod();
    }

    /**
     * getDispatcher 
     * 
     * @param array $events 
     * @param EventDispatcher $dispatcher 
     * @param string $eventNS 
     * @return EventDispatcher
     */
    protected function getDispatcher(array $events, EventDispatcher $dispatcher = null, $eventNS = 'test') 
    {
        $dispatcher = $dispatcher ?: new EventDispatcher();
        foreach($events as $event => $conf) {
            if(!is_array($conf)) { continue; }
            $dispatcher->addListener($eventNS.'.'.$event, function(Event $event) use ($conf) {
                foreach($conf as $key => $value) {
                    $event->setArgument($key, $value);
                }
            });
        }
        return $dispatcher;
    }


    /**
     * getProxy
     * 
     * @param array $methods 
     * @param EventDispatcher $dispatcher 
     * @param object $object 
     * @param string $eventNS 
     * @return object
     */
    protected function getProxy(
        array $methods,
        EventDispatcher $dispatcher = null,
        $object = null,
        $eventNS = 'test'
    ) {
        return (new EventDispatchingProxyFactory(
            $dispatcher ?: new EventDispatcher(),
            null,
            $this->useValueHolder
        ))->createProxy(
            $object ?: $this->getTestMock(),
            $methods, 
            $eventNS
        );
    }

    /**
     * getMock 
     * 
     * @param string $className 
     * @return object
     */
    protected function getTestMock($className = null) 
    {
        $mock = $this->getMockBuilder($className ?: get_class($this))
            ->disableOriginalConstructor()
            ->setMethods(array('firstMethod', 'secondMethod'))
            ->getMock();
        $mock->method('firstMethod')->willReturn('firstMethod');
        $mock->method('secondMethod')->willReturn('secondMethod');
        return $mock;
    }

    /**
     * testEventName 
     * 
     * @dataProvider providerEventName
     * @param array $methods 
     * @param array $call 
     * @param array $expected 
     * @param mixed $eventNS 
     * @return void
     */
    public function testEventName(array $methods, array $call, array $expected, $eventNS = null) 
    {
        /**
         * make real method names 
         */
        foreach(array(&$methods, &$call) as &$arr) {
            array_walk($arr, function (&$val, $key) { $val = $val.'Method'; });
        }

        $events = array();
        $dispatcher = $this->getEventsRegisteringDispatcher($events);
        $object = $this->getTestMock();
        $proxy = $this->getProxy($methods, $dispatcher, $object, $eventNS);
        
        $byProxyEventNS = Container::underscore((new \ReflectionClass($object))->getShortName());
        array_walk($expected, function(&$value) use ($byProxyEventNS) {
            $value = str_replace('%OBJECT_CLASS%', $byProxyEventNS, $value);

        });
        foreach($call as $method) {
            call_user_func(array($proxy, $method));
        }
        
        $this->assertEquals($expected, $events); 
    }

    /**
     * getEventsRegisteringDispatcher 
     * 
     * @param array $events 
     * @param EventDispatcher $dispatcher 
     * @return EventDispatcher
     */
    protected function getEventsRegisteringDispatcher(array &$events, EventDispatcher $dispatcher = null) 
    {
        return (new AccessInterceptorValueHolderFactory())
            ->createProxy($dispatcher ?: new EventDispatcher(), array(
                'dispatch' => function($proxy, $instance, $method, $params) use (&$events) { 
                    array_push($events, $params['eventName']);
                },
            )); 
    }

    /**
     * providerEventName 
     * 
     * @return array
     */
    public function providerEventName()
    {
        $events = function(array $methods, $eventNS = null) {
            $eventNS = $eventNS ?: '%OBJECT_CLASS%';
            $events = array();
            foreach($methods as $method) {
                $events[] = $eventNS.'.pre_'.$method.'_method';
                $events[] = $eventNS.'.post_'.$method.'_method'; 
            }
            return $events;
        };
        return array(
            // default event namespace
            array(['first'], ['first'], $events(['first'])),
            array(['first'], ['first', 'second'], $events(['first'])),
            array(['second'], ['first', 'second'], $events(['second'])),
            array(['first', 'second'], ['first', 'second'], $events(['first', 'second'])),
            array(['second'], ['first'], []),
            array(['first'], ['second'], []),
            array([], ['first', 'second'], []),

            // "test" event namespace
            array(['first'], ['first'], $events(['first'], 'test'), 'test'),
            array(['first'], ['second'], [], 'test'),
            array(['first', 'second'], ['first', 'second'], $events(['first', 'second'], 'test'), 'test'),
        );
    }

    /**
     * testReturnEarly 
     * 
     * @dataProvider providerReturnEarly
     * @param array $pre 
     * @param array $post 
     * @param mixed $expected 
     * @return void
     */
    public function testReturnEarly(array $pre, array $post, $expected) 
    {
        $proxy = $this->getProxy(
            array('firstMethod'),
            $this->getDispatcher(array(
                'pre_first_method' => $pre,
                'post_first_method' => $post,
            ))
        );
        $this->assertEquals($expected, $proxy->firstMethod());
    }

    /**
     * providerReturnEarly 
     * 
     * @return array
     */
    public function providerReturnEarly() 
    {
        $default = 'firstMethod';
        return array(
            array([], [], $default),
            array(['return_early' => true], [], null),
            array(['return_value' => 'pre'], [], 'pre'),
            array(['return_value' => 'pre'], ['return_value' => 'post'], 'pre'),
            array([], ['return_value' => 'post'], $default),
            array([], ['return_early' => true, 'return_value' => 'post'], 'post'),
            array([], ['return_early' => true, 'return_value' => null], null),
        );
    }

    /**
     * testMagicMethodOnGeneratedCall 
     * 
     * @return void
     */
    public function testMagicMethodOnGeneratedCall() 
    {
        $this->assertMagicMethod(true);
    }

    /**
     * testMagicMethodOnOrginalCall 
     * 
     * @dataProvider providerMagicMethodOnOrginalCall
     * @param bool $interceptOnPre 
     * @param bool $interceptOnPost 
     * @return void
     */
    public function testMagicMethodOnOrginalCall($interceptOnPre, $interceptOnPost) 
    {
        $this->assertMagicMethod(false, $interceptOnPre, $interceptOnPost);
    }

    /**
     * providerMagicMethodOnOrginalCall 
     * 
     * @return array
     */
    public function providerMagicMethodOnOrginalCall() 
    {
        return array(
            array(true, true),
            array(true, false),
            array(false, true),
            array(false, false),
        );
    }

    /**
     * testMagicMethod 
     * 
     * @param bool $generatedCall 
     * @param bool $doIntercept 
     * @return void
     */
    protected function assertMagicMethod($generatedCall = true, $interceptOnPre = true, $interceptOnPost = true) 
    {
        $expected = 'thirdMethod';
        $events = array();

        $proxy = $this->getProxy(
            array('firstMethod', 'thirdMethod'),
            $this->getDispatcher(
                array(
                    'pre_third_method' => $interceptOnPre  
                        ? array('return_value' => $expected) 
                        : array(),
                    'post_third_method' => array(
                        'return_value' => 'post:'.$expected,
                        'return_early' => $interceptOnPost,
                     ),
                ),
                $this->getEventsRegisteringDispatcher($events)
            ),
            $this->getTestMock(
                $generatedCall 
                    ? EventDispatchingProxyFactory::class
                    : get_class($this)
            ),
            'test'
        );
        $this->assertEquals(
            $generatedCall,
            (bool) preg_match( 
                $pattern = '/generated by .*EventDispatchingProxyFactory/',
                file_get_contents((new \ReflectionClass($proxy))->getFileName())
            ),
            sprintf(
                'proxy class file content %s match pattern "%s"',
                $generatedCall ? 'matches' : 'does not match',
                $pattern
            )
        );
        
        if(!$generatedCall) {
            $expected = $interceptOnPre ? 
                $expected : 
                ($interceptOnPost ? 'post:'.$expected : '__call');
        }
        
        $this->assertEquals('firstMethod', $proxy->firstMethod());
        $this->assertEquals($expected, $proxy->thirdMethod());
        $this->assertEquals($expected, $proxy->__call('thirdMethod', array()));
        
        $expectedEvents = array(
            'test.pre_first_method', 
            'test.post_first_method', 
        );
        for($n = 0; $n < 2; $n++) {
            $expectedEvents[] = 'test.pre_third_method';
            if(!$generatedCall && !$interceptOnPre) {
                $expectedEvents[] = 'test.post_third_method';
            }
        }
        $this->assertEquals($expectedEvents, $events);
    }


    /**
     * testValueHolderSwitch 
     * 
     * @return void
     */
    public function testValueHolderSwitch() 
    {
        $proxy = (new EventDispatchingProxyFactory(
            new EventDispatcher(), 
            null,
            $this->useValueHolder
        ))->createProxy($this, ['firstMethod']);

        if($this->useValueHolder) {
            $this->assertInstanceOf('\ProxyManager\Proxy\ValueHolderInterface', $proxy);
        } else {
            $this->assertNotInstanceOf('\ProxyManager\Proxy\ValueHolderInterface', $proxy);
        }
        $this->assertInstanceOf('\ProxyManager\Proxy\AccessInterceptorInterface', $proxy);

        return get_class($proxy);
    }

    /**
     * testProxyManagerConfiguration 
     * 
     * @runInSeparateProcess
     * @depends testValueHolderSwitch
     * @param string $proxyClass 
     * @return void
     */
    public function testProxyManagerConfiguration($proxyClass) 
    {
        $msg = function($msg) use ($proxyClass) { 
            return sprintf('Class "%s" %s', $proxyClass, $msg);
        };
        $this->assertFalse(class_exists($proxyClass), $msg('unavailable'));
        
        new EventDispatchingProxyFactory(
            new EventDispatcher(),
            null,
            $this->useValueHolder
        );
        $this->assertFalse(class_exists($proxyClass), $msg('still unavailable'));

        // factory with configuration object register autoloader
        new EventDispatchingProxyFactory(
            new EventDispatcher(),
            new Configuration(),
            $this->useValueHolder
        );
        $this->assertTrue(class_exists($proxyClass), $msg('now is available'));
    }
}


