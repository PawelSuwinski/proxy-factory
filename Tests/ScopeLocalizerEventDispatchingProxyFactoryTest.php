<?php
/**
 * This file is part of the PsuwProxyFactory package.
 *
 * @copyright Copyright (c) 2019 Paweł Suwiński
 * @author Paweł Suwiński <psuw@wp.pl>
 * @license MIT
 */

namespace Psuw\ProxyFactory\Tests;


/**
 * ScopeLocalizerEventDispatchingProxyFactoryTest
 *
 * @preserveGlobalState disabled
 * @package PsuwProxyFactory
 * @copyright Copyright (c) 2019, Paweł Suwiński
 * @author Paweł Suwiński <psuw@wp.pl> 
 * @license MIT
 */
class ScopeLocalizerEventDispatchingProxyFactoryTest extends EventDispatchingProxyFactoryTest 
{
    /**
     * {@inheritDoc}
     */
    protected $useValueHolder = false;
}

