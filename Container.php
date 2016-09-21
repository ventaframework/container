<?php declare(strict_types = 1);

namespace Venta\Container;

use Venta\Container\Contract\Container as ContainerContract;
use Venta\Container\Exception\ArgumentResolveException;
use Venta\Container\Exception\CircularReferenceException;
use Venta\Container\Exception\NotFoundException;
use Venta\Container\Exception\ResolveException;
use Closure;
use InvalidArgumentException;
use ReflectionClass;
use Throwable;

/**
 * Class Container
 *
 * @package Venta\Container
 */
class Container implements ContainerContract
{
    /**
     * Globally available container instance.
     *
     * @var ContainerContract
     */
    private static $instance;

    /**
     * Array of container service aliases.
     *
     * @var string[]
     */
    private $aliases = [];

    /**
     * Array of callable definitions.
     *
     * @var callable[]
     */
    private $callableDefinitions = [];

    /**
     * Array of class definitions.
     *
     * @var string[]
     */
    private $classDefinitions = [];

    /**
     * Array of container service callable factories.
     *
     * @var Closure[]
     */
    private $factories = [];

    /**
     * @var ObjectInflector
     */
    private $inflector;

    /**
     * Array of resolved instances.
     *
     * @var object[]
     */
    private $instances = [];

    /**
     * Array of container service identifiers.
     *
     * @var string[]
     */
    private $keys = [];

    /**
     * @var ArgumentResolver
     */
    private $resolver;

    /**
     * Array of container service identifiers currently being resolved.
     *
     * @var string[]
     */
    private $resolving = [];

    /**
     * Array of instances identifiers marked as shared.
     * Such instances will be instantiated once and returned on consecutive gets.
     *
     * @var bool[]
     */
    private $shared = [];

    /**
     * Container constructor.
     *
     * @param ArgumentResolver|null $resolver
     * @param ObjectInflector|null $inflector
     */
    public function __construct(
        ArgumentResolver $resolver = null,
        ObjectInflector $inflector = null
    ) {
        $this->resolver = $resolver ?? new ArgumentResolver($this);
        $this->inflector = $inflector ?? new ObjectInflector($this->resolver);
    }

    /**
     * Get container instance.
     *
     * @return ContainerContract
     */
    public static function getInstance(): ContainerContract
    {
        if (static::$instance === null) {
            static::$instance = new static;
        }

        return static::$instance;
    }

    /**
     * @inheritDoc
     */
    public function alias(string $id, string $alias)
    {
        $this->validateAlias($alias);
        $this->addAlias($id, $alias);
    }

    /**
     * @inheritDoc
     * @param callable|string $callable Callable to call OR class name to instantiate and invoke.
     */
    public function call($callable, array $arguments = [])
    {
        if (is_string($callable) && method_exists($callable, '__invoke')) {
            // We allow to call class by name if `__invoke()` method is implemented.
            $callable = [$callable, '__invoke'];
        } elseif (!is_callable($callable)) {
            throw new InvalidArgumentException(sprintf("Callable expected, '%s' is given.", gettype($callable)));
        }

        return ($this->createServiceFactoryFromCallable($callable))($arguments);
    }


    /**
     * @inheritDoc
     */
    public function get($id, array $arguments = [])
    {
        $originalId = $id;
        $id = $this->normalize($id);
        // We try to resolve alias first to get a real service id.
        $id = $this->aliases[$id] ?? $id;
        if (!$this->isResolvableService($id)) {
            throw new NotFoundException($originalId, $this->resolving);
        }

        // Look up service in resolved instances first.
        if (isset($this->instances[$id])) {
            return $this->instances[$id];
        }

        // Detect circular references.
        // We mark service as being resolved to detect circular references through out the resolution chain.
        if (isset($this->resolving[$id])) {
            throw new CircularReferenceException($originalId, $this->resolving);
        } else {
            $this->resolving[$id] = $originalId;
        }

        return $this->resolveService($id, $arguments);
    }

    /**
     * @inheritDoc
     */
    public function has($id): bool
    {
        return $this->isResolvableService($this->normalize($id));
    }

    /**
     * @inheritDoc
     */
    public function inflect(string $id, string $method, array $arguments = [])
    {
        $this->validateId($id);
        if (!method_exists($id, $method)) {
            throw new InvalidArgumentException(sprintf('Method "%s" not found in "%s".', $method, $id));
        }

        $this->inflector->addInflection($this->normalize($id), $method, $arguments);
    }

    /**
     * @inheritDoc
     */
    public function set(string $id, $service, array $aliases = [])
    {
        $this->validateId($id);
        foreach ($aliases as $alias) {
            $this->validateAlias($alias);
        }

        $this->registerService($id, $service);
        foreach ($aliases as $alias) {
            $this->addAlias($id, $alias);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function share(string $id, $service, array $aliases = [])
    {
        $this->set($id, $service, $aliases);
        $this->shared[$this->normalize($id)] = true;
    }

    /**
     * @inheritDoc
     */
    protected function getContainer(): Container
    {
        return $this;
    }

    /**
     * Forbid container cloning.
     *
     * @codeCoverageIgnore
     */
    private function __clone()
    {
    }

    /**
     * Store service alias to the list.
     *
     * @param string $id
     * @param string $alias
     */
    private function addAlias(string $id, string $alias)
    {
        $this->aliases[$this->normalize($alias)] = $this->normalize($id);
    }

    /**
     * Create callable factory for the subject service.
     *
     * @param string $id
     * @return Closure
     */
    private function createServiceFactory(string $id): Closure
    {
        if (isset($this->callableDefinitions[$id])) {
            return $this->createServiceFactoryFromCallable($this->callableDefinitions[$id]);
        }

        return $this->createServiceFactoryFromClassName($this->classDefinitions[$id] ?? $id);
    }

    /**
     * Create callable factory with resolved arguments from callable.
     *
     * @param callable $callable
     * @return Closure
     */
    private function createServiceFactoryFromCallable($callable): Closure
    {
        if (is_string($callable) && strpos($callable, '::') !== false) {
            $callable = explode('::', $callable);
        }

        if ($this->isConcrete($callable)) {
            $callable = [$callable, '__invoke'];
        }

        if (is_array($callable)) {
            return function (array $arguments = []) use ($callable) {

                $reflection = $this->resolver->reflectCallable($callable);

                if ($reflection->isStatic()) {
                    $object = null;
                } elseif (is_string($callable[0])) {
                    $object = $this->get($callable[0]);
                } else {
                    $object = $callable[0];
                }

                return $reflection->invokeArgs($object, ($this->resolver->resolveArguments($reflection))($arguments));
            };
        }

        return function (array $arguments = []) use ($callable) {
            return $callable(
                ...($this->resolver->resolveArguments($this->resolver->reflectCallable($callable)))($arguments)
            );
        };
    }

    /**
     * Create callable factory with resolved arguments from class name.
     *
     * @param string $className
     * @return Closure
     */
    private function createServiceFactoryFromClassName(string $className): Closure
    {
        $constructor = (new ReflectionClass($className))->getConstructor();
        $resolve = ($constructor && $constructor->getNumberOfParameters())
            ? $this->resolver->resolveArguments($constructor)
            : null;

        return function (array $args = []) use ($className, $resolve) {
            $object = $resolve ? new $className(...$resolve($args)) : new $className();

            return $object;
        };
    }

    /**
     * Check if subject service is a closure.
     *
     * @param $service
     * @return bool
     */
    private function isClosure($service): bool
    {
        return $service instanceof Closure;
    }

    /**
     * Check if subject service is an object instance.
     *
     * @param mixed $service
     * @return bool
     */
    private function isConcrete($service): bool
    {
        return is_object($service) && !$this->isClosure($service);
    }

    /**
     * Check if container can resolve the service with subject identifier.
     *
     * @param string $id
     * @return bool
     */
    private function isResolvableService(string $id): bool
    {
        return isset($this->keys[$id]) || class_exists($id);
    }

    /**
     * Normalize key to use across container.
     *
     * @param  string $id
     * @return string
     */
    private function normalize(string $id): string
    {
        return strtolower(ltrim($id, '\\'));
    }

    /**
     * Set new container service definition.
     *
     * @param string $id
     * @param $service
     * @throws InvalidArgumentException
     */
    private function registerService(string $id, $service)
    {
        $originalId = $id;
        $id = $this->normalize($id);

        if (is_callable($service)) {
            $this->callableDefinitions[$id] = $service;

        } elseif (is_string($service)) {

            if (!class_exists($service)) {
                throw new InvalidArgumentException(sprintf('Class "%s" does not exist.', $service));
            }
            $this->classDefinitions[$id] = $service;

        } elseif ($this->isConcrete($service)) {
            $this->instances[$id] = $service;
            $this->shared[$id] = true;
        } else {
            throw new InvalidArgumentException(sprintf('Invalid service "%s" type.', $originalId));
        }

        $this->keys[$id] = $originalId;
    }

    /**
     * @param string $id
     * @param array $arguments
     * @return mixed
     * @throws Throwable
     */
    private function resolveService(string $id, array $arguments = [])
    {
        try {
            // Create service factory closure.
            if (!isset($this->factories[$id])) {
                $this->factories[$id] = $this->createServiceFactory($id);
            }

            // Instantiate service and apply inflections.
            $object = $this->factories[$id]($arguments);
            $this->inflector->applyInflections($object);

            // Cache shared instances.
            if (isset($this->shared[$id])) {
                $this->instances[$id] = $object;
            }

            return $object;
        } catch (ArgumentResolveException $resolveException) {
            throw new ResolveException($id, $this->resolving, $resolveException);
        } catch (Throwable $e) {
            throw $e;
        } finally {
            unset($this->resolving[$id]);
        }
    }

    /**
     * Validate service alias. Throw an Exception in case of invalid value.
     *
     * @param string $alias
     * @throws InvalidArgumentException
     */
    private function validateAlias(string $alias)
    {
        if (isset($this->aliases[$this->normalize($alias)])) {
            throw new InvalidArgumentException(sprintf('Invalid alias "%s".', $alias));
        }
    }

    /**
     * Validate service identifier. Throw an Exception in case of invalid value.
     *
     * @param string $id
     * @return void
     * @throws InvalidArgumentException
     */
    private function validateId(string $id)
    {
        if (!interface_exists($id) && !class_exists($id)) {
            throw new InvalidArgumentException(sprintf(
                    'Invalid service id "%s". Service id must be an existing interface or class name.', $id
                )
            );
        }
    }
}
