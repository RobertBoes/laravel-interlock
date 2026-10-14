<?php

namespace RobertBoes\Interlock\Discovery;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use RobertBoes\Interlock\Attributes\UseStateMachine;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\StateMachine;
use SplFileInfo;

/**
 * Knows every interlocked model in the app: the ones found in the configured paths, plus the ones registered explicitly.
 */
final class ModelRegistry
{
    /** @var list<class-string<Model>> */
    private array $registered = [];

    /** @var array<class-string<Model>, array<string, class-string<StateMachine>>>|null */
    private ?array $models = null;

    public function __construct(
        private readonly Repository $config,
        private readonly string $cachePath,
    ) {}

    /**
     * @param  list<class-string<Model>>  $models
     */
    public function register(array $models): void
    {
        $this->registered = [...$this->registered, ...$models];
        $this->models = null;
    }

    /**
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>
     */
    public function models(): array
    {
        return $this->models ??= $this->withRegistered($this->cached() ?? $this->scan());
    }

    /**
     * Scan the configured paths, whether or not a cache exists, and write what is found to the cache.
     */
    public function cache(): void
    {
        file_put_contents(
            $this->cachePath,
            '<?php' . PHP_EOL . PHP_EOL . 'return ' . var_export($this->withRegistered($this->scan()), true) . ';' . PHP_EOL,
        );
    }

    public function clearCache(): void
    {
        if (is_file($this->cachePath)) {
            unlink($this->cachePath);
        }
    }

    /**
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>|null
     */
    private function cached(): ?array
    {
        return is_file($this->cachePath) ? require $this->cachePath : null;
    }

    /**
     * @param  array<class-string<Model>, array<string, class-string<StateMachine>>>  $models
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>
     */
    private function withRegistered(array $models): array
    {
        foreach ([...$this->config->get('interlock.models', []), ...$this->registered] as $model) {
            $models[$model] ??= $this->machinesOfRegistered($model);
        }

        return $models;
    }

    /**
     * @param  class-string<Model>  $model
     * @return array<string, class-string<StateMachine>>
     */
    private function machinesOfRegistered(string $model): array
    {
        return $this->machinesOf(new ReflectionClass($model))
            ?: throw InvalidDefinitionException::registeredModelWithoutStateMachine($model);
    }

    /**
     * @return array<class-string<Model>, array<string, class-string<StateMachine>>>
     */
    private function scan(): array
    {
        $models = [];

        foreach ($this->config->get('interlock.discovery.paths', []) as $path) {
            foreach ($this->classesIn($path) as $class) {
                if (! is_subclass_of($class, Model::class)) {
                    continue;
                }

                $machines = $this->machinesOf(new ReflectionClass($class));

                if ($machines !== []) {
                    $models[$class] = $machines;
                }
            }
        }

        return $models;
    }

    /**
     * @return iterable<class-string>
     */
    private function classesIn(string $path): iterable
    {
        if (! is_dir($path)) {
            return;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $this->classDeclaredIn((string) file_get_contents($file->getPathname()));

            if ($class !== null && class_exists($class)) {
                yield $class;
            }
        }
    }

    private function classDeclaredIn(string $code): ?string
    {
        if (! preg_match('/^\s*(?:(?:abstract|final|readonly)\s+)*class\s+(\w+)/m', $code, $classMatch)) {
            return null;
        }

        $namespace = preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $code, $namespaceMatch) ? $namespaceMatch[1] . '\\' : '';

        return $namespace . $classMatch[1];
    }

    /**
     * A model that declares machines must use HasStateMachines, or nothing enforces them and its triggers never run.
     *
     * @param  ReflectionClass<Model>  $model
     * @return array<string, class-string<StateMachine>>
     */
    private function machinesOf(ReflectionClass $model): array
    {
        $machines = [];

        foreach ($model->getAttributes(UseStateMachine::class) as $attribute) {
            $declaration = $attribute->newInstance();
            $machines[$declaration->attribute] = $declaration->machine;
        }

        if ($machines !== [] && ! in_array(HasStateMachines::class, class_uses_recursive($model->getName()), true)) {
            throw InvalidDefinitionException::modelWithoutHasStateMachines($model->getName());
        }

        return $machines;
    }
}
