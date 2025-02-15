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
/**
 * This is a simple class that uses reflection to figure out the dependencies
 * of a method and attempts to return them using the Injector instance.
 *
 * @author    Bob Mckee <bmckee@bywires.com>
 * @author    James Pepin <james@jamespepin.com>
 * @author    Chuck Hagenbuch <chuck@horde.org>
 * @category  Horde
 * @copyright 2009-2020 Horde LLC
 * @license   http://www.horde.org/licenses/bsd BSD
 * @package   Injector
 */
class DependencyFinder
{
    /**
     * @param Injector $injector
     * @param \ReflectionMethod $method
     *
     * @return array
     * @throws Exception
     */
    public function getMethodDependencies(Injector $injector,
                                          \ReflectionMethod $method): array
    {
        $dependencies = [];

        try {
            foreach ($method->getParameters() as $parameter) {
                $dependencies[] = $this->getParameterDependency($injector, $parameter);
            }
        } catch (Exception $e) {
            throw new Exception("$method has unfulfilled dependencies ($parameter)", 0, $e);
        }

        return $dependencies;
    }

    /**
     * @param Injector $injector
     * @param \ReflectionParameter $method
     *
     * @return mixed
     * @throws Exception
     */
    public function getParameterDependency(Injector $injector,
                                           \ReflectionParameter $parameter)
    {
        if ($parameter->getClass()) {
            return $injector->getInstance($parameter->getClass()->getName());
        }
        if ($type = $parameter->getType()) {
            $instance = $injector->getInstance($type);
            if ($instance) {
                return $instance;
            }
            $instance = $injector->getInstance('\\' . $type);
            if ($instance) {
                return $instance;
            }
        }
        if ($parameter->isOptional()) {
            return $parameter->getDefaultValue();
        }

        throw new Exception("Untyped parameter \$" . $parameter->getName() . "can't be fulfilled");
    }

}
