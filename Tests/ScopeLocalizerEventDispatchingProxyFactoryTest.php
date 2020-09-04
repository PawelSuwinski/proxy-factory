<?php
/**
 * This file is part of the psProxyFactory package.
 *
 * @copyright Copyright (c) 2019 Paweł Suwiński
 * @author Paweł Suwiński <psuw@wp.pl>
 * @license MIT
 */

namespace ps\ProxyFactory\Tests;


/**
 * ScopeLocalizerEventDispatchingProxyFactoryTest
 *
 * @preserveGlobalState disabled
 * @package psProxyFactory
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

