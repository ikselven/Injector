<?php
/**
 * Copyright 2009-2020 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (BSD). If you
 * did not receive this file, see http://www.horde.org/licenses/bsd.
 *
 * @category  Horde
 * @copyright 2009-2020 Horde LLC
 * @license   http://www.horde.org/licenses/bsd BSD
 * @package   Injector
 */
namespace Horde\Injector;
use Psr\Container\ContainerInterface;

/**
 * Injector class for injecting dependencies of objects
 *
 * This class is responsible for injecting dependencies of objects.  It is
 * inspired by the bucket_Container's concept of child scopes, but written to
 * support many different types of bindings as well as allowing for setter
 * injection bindings.
 *
 * @author    Bob Mckee <bmckee@bywires.com>
 * @author    James Pepin <james@jamespepin.com>
 * @category  Horde
 * @copyright 2009-2020 Horde LLC
 * @license   http://www.horde.org/licenses/bsd BSD
 * @package   Injector
 */
class Injector implements Scope, ContainerInterface
{
    /**
     * @var array
     */
    private $bindings = array();

    /**
     * @var array
     */
    private $instances;

    /**
     * @var Scope
     */
    private $parentInjector;

    /**
     * Reflection cache.
     *
     * @var array
     */
    private $reflection = array();

    /**
     * Create a new Injector object.
     *
     * Every injector object has a parent scope.  For the very first
     * Injector, you should pass it a TopLevel object.
     *
     * @param Scope $injector  The parent scope.
     */
    public function __construct(Scope $injector)
    {
        $this->parentInjector = $injector;
        $this->instances = [__CLASS__ => $this];
    }

    /**
     * Create a child injector that inherits this injector's scope.
     *
     * All child injectors inherit the parent scope.  Any objects that were
     * created using getInstance, will be available to the child container.
     * The child container can set bindings to override the parent, and none
     * of those bindings will leak to the parent.
     *
     * @return Injector  A child injector with $this as its parent.
     */
    public function createChildInjector(): Injector
    {
        // Using self is wrong and breaks wrapping into inheriting injectors
        $thisOrDerivedClass = \get_class($this);
        return new $thisOrDerivedClass($this);
    }

    /**
     * Method overloader.  Handles $this->bind[BinderType] type calls.
     *
     * @return Binder  See bind().
     */
    public function __call(string $name, iterable $args = []): Binder
    {
        if (substr($name, 0, 4) == 'bind') {
            return $this->bind(substr($name, 4), $args);
        }

        throw new \BadMethodCallException('Call to undefined method ' . __CLASS__ . '::' . $name . '()');
    }

    /**
     * Method that creates binders to send to addBinder(). This is called by
     * the magic method __call() whenever a function is called that starts
     * with bind.
     *
     * @param string $type  The type of Horde\Injector\Binder\* to be created.
     *                      Matches /^Horde\Injector\Binder\(\w+)$/.
     * @param array $args   The constructor arguments for the binder object.
     *
     * @return Binder  The binder object created. Useful for
     *                                method chaining.
     */
    private function bind(string $type, iterable $args = []): Binder
    {
        if (!($interface = array_shift($args))) {
            throw new \BadMethodCallException('First parameter for "bind' . $type . '" must be the name of an interface or class');
        }

        if (!isset($this->reflection[$type])) {
            $rc = new \ReflectionClass('Horde\Injector\Binder\\' . $type);
            $this->reflection[$type] = array(
                $rc,
                (bool)$rc->getConstructor()
            );
        }

        $this->addBinder(
            $interface,
            $this->reflection[$type][1]
                ? $this->reflection[$type][0]->newInstanceArgs($args)
                : $this->reflection[$type][0]->newInstance()
        );

        return $this->getBinder($interface);
    }

    /**
     * Add a Horde\Injector\Binder to an interface
     *
     * This is the method by which we bind an interface to a concrete
     * implentation or factory.  For convenience, binders may be added by
     * $this->bind[BinderType].
     *
     * <pre>
     * bindFactory - Creates a Horde\Injector\Binder\Factory
     * bindImplementation - Creates a Horde\Injector\Binder\Implementation
     * </pre>
     *
     * All subsequent arguments are passed to the constructor of the
     * Horde\Injector\Binder object.
     *
     * @param string $interface              The interface to bind to.
     * @param Binder $binder                 The binder to be bound to the
     *                                       specified $interface.
     *
     * @return Injector  A reference to itself for method chaining.
     */
    public function addBinder(string $interface, Binder $binder): Injector
    {
        // First we check to see if our parent already has an equal binder set.
        // if so we don't need to do anything
        if (!$binder->equals($this->parentInjector->getBinder($interface))) {
            $this->bindings[$interface] = $binder;
        }
        return $this;
    }

    /**
     * Get the Binder associated with the specified instance.
     *
     * Binders are objects responsible for binding a particular interface
     * with a class. If no binding is set for this object, the parent scope is
     * consulted.
     *
     * @param string $interface  The interface to retrieve binding information
     *                           for.
     *
     * @return Binder            The binding set for the specified
     *                                interface.
     */
    public function getBinder(string $interface): ?Binder
    {
        return isset($this->bindings[$interface])
            ? $this->bindings[$interface]
            : $this->parentInjector->getBinder($interface);
    }

    /**
     * Set the object instance to be retrieved by getInstance the next time
     * the specified interface is requested.
     *
     * This method allows you to set the cached object instance so that all
     * subsequent getInstance() calls return the object you have specified.
     *
     * @param string $interface  The interface to bind the instance to.
     * @param mixed $instance    The object instance to be bound to the
     *                           specified instance.
     *
     * @return Injector  A reference to itself for method chaining.
     */
    public function setInstance(string $interface, $instance): Injector
    {
        $this->instances[$interface] = $instance;
        return $this;
    }

    /**
     * Create a new instance of the specified object/interface.
     *
     * This method creates a new instance of the specified object/interface.
     * NOTE: it does not save that instance for later retrieval. If your
     * object should be re-used elsewhere, you should be using getInstance().
     *
     * @param string $interface  The interface name, or object class to be
     *                           created.
     *
     * @return mixed  A new object that implements $interface.
     */
    public function createInstance(string $interface)
    {
        return $this->getBinder($interface)->create($this);
    }

    /**
     * Retrieve an instance of the specified object/interface.
     *
     * PSR-11 ContainerInterface Version
     * 
     * This method gets you an instance, and saves a reference to that
     * instance for later requests.
     *
     * Interfaces must be bound to a concrete class to be created this way.
     * Concrete instances may be created through reflection.
     *
     * It does not gaurantee that it is a new instance of the object.  For a
     * new instance see createInstance().
     *
     * @param string $interface  The interface name, or object class to be
     *                           created.
     *
     * @return mixed  An object that implements $interface, but not
     *                necessarily a new one.
     * 
     * @throws Horde\Injector\NotFoundException
     */
    public function get($interface)
    {
        try { // Do we have an instance?
            if (!$this->has($interface)) {
                // Do we have a binding for this interface? If so then we don't
                // ask our parent.
                if (!isset($this->bindings[$interface]) &&
                    // Does our parent have an instance?
                    ($instance = $this->parentInjector->get($interface))) {
                    return $instance;
                }

                // We have to make our own instance
                $this->setInstance($interface, $this->createInstance($interface));
            }
        } catch (Exception $e) {
            throw new NotFoundException($e->getMessage(), $e->getCode(), $e);
        }
        return $this->instances[$interface];
    }
    /**
     * Retrieve an instance of the specified object/interface.
     *
     * Horde 5 compatible call. Refactor to get()
     * 
     * This method gets you an instance, and saves a reference to that
     * instance for later requests.
     *
     * Interfaces must be bound to a concrete class to be created this way.
     * Concrete instances may be created through reflection.
     *
     * It does not gaurantee that it is a new instance of the object.  For a
     * new instance see createInstance().
     *
     * @param string $interface  The interface name, or object class to be
     *                           created.
     *
     * @return mixed  An object that implements $interface, but not
     *                necessarily a new one.
     */
    public function getInstance(string $interface)
    {
        return $this->get($interface);
    }


    /**
     * Has the interface for the specified object/interface been created yet?
     * 
     * PSR-11 ContainerInterface version
     *
     * @param string $interface  The interface name or object class.
     *
     * @return bool  True if the instance already has been created.
     */
    public function has($interface): bool
    {
        return isset($this->instances[$interface]);
    }

    /**
     * Has the interface for the specified object/interface been created yet?
     * 
     * Horde 5 compatible call. Refactor to has()
     *
     * @param string $interface  The interface name or object class.
     *
     * @return bool  True if the instance already has been created.
     */
    public function hasInstance($interface): bool
    {
        return isset($this->instances[$interface]);
    }
}
