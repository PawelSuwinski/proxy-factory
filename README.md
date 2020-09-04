README
=======

Generates proxy for a given object dispatching pre and post execute events
on indicated methods as a way in runtime dynamic class extending.

@package psProxyFactory  
@copyright Copyright (c) 2019, Paweł Suwiński  
@author Paweł Suwiński <psuw@wp.pl>  
@license MIT  


Example of usage
-----------------

Configured global Request::get() validator: 

```
# config.yml 
imports:
    - { resource: parameters.yml }

services:
# (...)

    # Validate or sanitize every Request::get() call
    request_validator: 
        class: (...)
        arguments: [ '%request_validator_config%' ]
        tags: [{ name: kernel.event_listener, event: request.post_get}]

    # Replace every Request ParameterBag object with proxied one
    request_parameter_bag_replace: 
        class: (...)
        tags: [{ name: kernel.event_listener, event: kernel.request, priority: 140}]

    # ParameterBag proxy for get() and quote() methods dispatching
    # request.pre_get and request.post_get events on ParameterBag::get() 
    # request.pre_quote and request.post_quote on ParameterBag::quote()
    request_parameter_bag_proxy:
        class: Symfony\Component\HttpFoundation\ParameterBag
        shared: false
        factory: ['@proxy_factory', 'createProxy']
        arguments: ['@parameter_bag', ['get', 'quote'], 'request']
    parameter_bag:
        class: Symfony\Component\HttpFoundation\ParameterBag
        shared: false

    proxy_factory:
        class: mm\TerminarzBundle\ProxyFactory\EventDispatchingProxyFactory
        arguments: ['@event_dispatcher', '@proxy_config']

    proxy_config:
        class: ProxyManager\Configuration
        calls:
            - [setProxiesTargetDir, ['%kernel.cache_dir%']]
            - [setGeneratorStrategy, ['@proxy_manager.generator_strategy']]
    proxy_manager.generator_strategy:
        arguments: ['@proxy_manager.file_locator']
        class: ProxyManager\GeneratorStrategy\FileWriterGeneratorStrategy
    proxy_manager.file_locator:
        class: ProxyManager\FileLocator\FileLocator
        arguments: ['%kernel.cache_dir%']
```

