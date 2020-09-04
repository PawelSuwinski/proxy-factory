<?php
/**
 * This file is part of the PsuwProxyFactory package.
 *
 * @copyright Copyright (c) 2019 Paweł Suwiński
 * @author Paweł Suwiński <psuw@wp.pl>
 * @license MIT
 */

namespace Psuw\ProxyFactory;

use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\DependencyInjection\Container;
use ProxyManager\Configuration;
use ProxyManager\FileLocator\FileLocator;
use ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy;
use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use ProxyManager\Factory\AccessInterceptorScopeLocalizerFactory;
use ProxyManager\Generator\Util\ClassGeneratorUtils;
use ProxyManager\Generator\MagicMethodGenerator;
use Laminas\Code\Generator\ParameterGenerator;

/**
 * EventDispatchingProxyFactory 
 *
 * Generates proxy for a given object dispatching pre and post execute 
 * events on indicated methods.
 * 
 * @see ProxyManager\Factory\AccessInterceptorValueHolderFactory
 * @see ProxyManager\Factory\AccessInterceptorScopeLocalizerFactory
 * @package PsuwProxyFactory
 * @copyright Copyright (c) 2019, Paweł Suwiński
 * @author Paweł Suwiński <psuw@wp.pl> 
 * @license MIT
 */
class EventDispatchingProxyFactory 
{

    /**
     * @var EventDispatcherInterface
     */
    protected $dispatcher;

    /**
     * @var Configuration
     */
    protected $config;

    /**
     * useValueHolder 
     * 
     * @var bool
     */
    protected $useValueHolder;

    /**
     * __construct 
     * 
     * @param EventDispatcherInterface $dispatcher 
     * @param mixed $useValueHolder 
     * @return void
     */
    public function __construct(EventDispatcherInterface $dispatcher, Configuration $config = null, $useValueHolder = true)
    {
        $this->dispatcher = $dispatcher;
        if($config === null) {
            $config = new Configuration();
            $config->setGeneratorStrategy(new FileWriterGeneratorStrategy(
                new FileLocator($config->getProxiesTargetDir())
            ));
        } else {
            spl_autoload_register($config->getProxyAutoloader());
        }
        $this->config = $config;
        $this->useValueHolder = (bool) $useValueHolder;
    }

    /**
     * createProxy 
     * 
     * @param object $object 
     * @param array $methods 
     * @param string $eventNS 
     * @return object
     */
    public function createProxy($object, array $methods, $eventNS = null) 
    {
        if(!is_object($object)) {
            throw new \InvalidArgumentException('Object expected!');
        }

        $eventNS = $eventNS ?: Container::underscore(
            (new \ReflectionClass($object))->getShortName()
        );
        
        $magicCallMethods = array();
        $methods = array_filter(
            $methods, 
            function($method) use (&$magicCallMethods, $object) {
                if(method_exists($object, $method)) {
                    return true;
                }
                if(is_string($method)) {
                    array_push($magicCallMethods, $method);
                }
                return false;
            }
        );
        $generateMagicCall = false;
        if(!empty($magicCallMethods) && !in_array('__call', $methods)) {
            array_push($methods, '__call');
            if(!method_exists($object, '__call')) {
                $generateMagicCall = true;
            }
        }

        $pre = array();
        $post = array();
        foreach($methods as $method) {
            $pre[$method] = function($proxy, $instance, $method, $params, & $returnEarly)
                    use ($eventNS) { 
                $event = $this->dispatchEvent($eventNS.'.pre_', $proxy, $instance, $method, $params, null, $returnEarly);
                if($event->getArgument('return_value') !== null) {
                    $returnEarly = true;
                }
                return $event->getArgument('return_value');
            };
            /**
             *  no parent::__call(), post event not needed
             */
            if($method == '__call' && $generateMagicCall) {
                continue;
            }
            $post[$method] = function($proxy, $instance, $method, $params, $returnValue, & $returnEarly)
                    use ($eventNS) { 
                return $this->dispatchEvent($eventNS.'.post_', $proxy, $instance, $method, $params, $returnValue, $returnEarly)
                    ->getArgument('return_value');
            };
        }

        return $this->getFactory(
            $generateMagicCall
                ? $this->getExtendedConfig($object, $magicCallMethods)
                : $this->config
        )->createProxy($object, $pre, $post);
    }
     
    /**
     * getFactory 
     * 
     * @param Configuration $config 
     * @return AccessInterceptorValueHolderFactory|AccessInterceptorScopeLocalizerFactory
     */
    protected function getFactory(Configuration $config) {
        return $this->useValueHolder 
            ? new AccessInterceptorValueHolderFactory($config)
            : new AccessInterceptorScopeLocalizerFactory($config);
    }
    
    /**
     * getExtendedConfig 
     *
     * Returns clone of the given factory configuration with extension 
     * that adds extra __call() method body to the generated class.
     * 
     * @param object $object 
     * @param array $magicCallMethods 
     * @return Configuration
     */
    protected function getExtendedConfig($object, array $magicCallMethods) {
        $strategy = (new AccessInterceptorValueHolderFactory($this->config))->createProxy(
                $this->config->getGeneratorStrategy(),
                array(
                    'generate' => function($proxy, $instance, $method, $params) 
                            use ($object, $magicCallMethods) {

                        $originalClass = new \ReflectionClass($object);
                        $parameter = new ParameterGenerator('arguments');
                        $parameter->setType('array');
                        $generatedMethod = new MagicMethodGenerator(
                            $originalClass, 
                            '__call',
                            array(new ParameterGenerator('name'), $parameter)
                        );

                        $classGenerator = $params['classGenerator'];
                        $properties = $classGenerator->getProperties();
                        $interceptorGeneratorClass = sprintf(
                            '\ProxyManager\ProxyGenerator\%s\MethodGenerator\Util\InterceptorGenerator',
                            $this->useValueHolder
                                ? 'AccessInterceptorValueHolder'
                                : 'AccessInterceptorScopeLocalizer'
                        ); 
                        $exceptionCode = 'throw new \BadMethodCallException('.
                            'sprintf(\'Method "%s::%s" not exists!\','.
                            ' get_parent_class($this), $name));';
                        $generatedMethod->setBody(
                            'if(!preg_match(\'/^('.
                                implode('|', $magicCallMethods).")\$/', \$name)) {\n".
                                "    $exceptionCode\n}\n\n".
                            $interceptorGeneratorClass::createInterceptedMethodBody(
                                $exceptionCode,
                                $generatedMethod,
                                reset($properties),
                                next($properties),
                                $this->useValueHolder ? next($properties) : null,
                                null
                            )
                        );
                        $generatedMethod->setDocblock(
                            'generated by '.__CLASS__."\n\n".
                            '@param string $name'."\n".
                            '@param array $arguments'
                        );

                        ClassGeneratorUtils::addMethodIfNotFinal(
                            $originalClass, 
                            $classGenerator,
                            $generatedMethod
                        );
                    },
                ) 
            );
        $config = clone $this->config;
        $config->setGeneratorStrategy($strategy);
        return $config;
    }

    /**
     * dispatchEvent 
     * 
     * @param mixed $eventName 
     * @param \ProxyManager\Proxy\AccessInterceptorInterface|\ProxyManager\Proxy\ValueHolderInterface $proxy 
     * @param object $instance 
      @param string $method 
     * @param array $params 
     * @param mixed $returnValue 
     * @param bool $&returnEarly 
     * @return GenericEvent
     */
    private function dispatchEvent($eventName, $proxy, $instance, $method, array $params, $returnValue, & $returnEarly) 
    {
        return $this->dispatcher->dispatch(
            $eventName.Container::underscore(
                ($method == '__call') ? reset($params) : $method
            ),
            new GenericEvent($instance, array(
                'proxy' => $proxy,
                'method' => $method,
                'params' => ($method == '__call') 
                    ? array_map(
                        function($v) { 
                            return is_array($v) ? new \ArrayObject($v) : $v; 
                        },
                        $params
                    )
                    : new \ArrayObject($params),
                'return_early' => & $returnEarly,
                'return_value' => $returnValue,
            ))
        );
    }
}
