<?php

declare(strict_types=1);

namespace Ipsocode\Scribe\Extracting;

use Exception;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Arr;
use Ipsocode\Scribe\Tools\ConsoleOutputUtils as c;
use Ipsocode\Scribe\Tools\ErrorHandlingUtils as e;
use Ipsocode\Scribe\Tools\Globals;
use Ipsocode\Scribe\Tools\Utils;
use ReflectionFunctionAbstract;
use ReflectionNamedType;
use Throwable;

trait InstantiatesExampleModels
{
    /**
     * @param string[] $factoryStates
     * @param string[] $relations
     * @param null|ReflectionFunctionAbstract $transformationMethod a method whose first parameter is typed with the model class, used when `$type` is null
     * @return null|Model|object
     */
    protected function instantiateExampleModel(
        ?string $type = null,
        array $factoryStates = [],
        array $relations = [],
        ?ReflectionFunctionAbstract $transformationMethod = null,
        array $withCount = [],
    ) {
        // If the API Resource uses an empty resource, there won't be an example model
        if ($type === null && $transformationMethod === null) {
            return null;
        }

        if ($type === null) {
            $parameter = Arr::first($transformationMethod->getParameters());
            $parameterType = $parameter->hasType() ? $parameter->getType() : null;
            if ($parameterType instanceof ReflectionNamedType
                && ! $parameterType->isBuiltin() && class_exists($parameterType->getName())) {
                $type = $parameterType->getName();
            }
        }
        if ($type === null) {
            throw new Exception("Couldn't detect a transformer model from your doc block. Did you remember to specify a model using @transformerModel?");
        }

        $configuredStrategies = $this->config->get('examples.models_source', ['factoryCreate', 'factoryMake', 'databaseFirst']);

        $strategies = [
            'factoryCreate' => fn () => $this->getExampleModelFromFactoryCreate($type, $factoryStates, $relations, $withCount),
            'factoryCreateQuietly' => fn () => $this->getExampleModelFromFactoryCreate($type, $factoryStates, $relations, $withCount, true),
            'factoryMake' => fn () => $this->getExampleModelFromFactoryMake($type, $factoryStates, $relations),
            'databaseFirst' => fn () => $this->getExampleModelFromDatabaseFirst($type, $relations, $withCount),
        ];

        $this->seedFactoryFaker();

        foreach ($configuredStrategies as $strategyName) {
            try {
                $model = $strategies[$strategyName]();
                if ($model) {
                    return $model;
                }
            } catch (Throwable $e) {
                c::warn("Couldn't get example model for {$type} via {$strategyName}.");
                e::dumpExceptionIfVerbose($e);
            }
        }

        return new $type;
    }

    /** Whether seedFactoryFaker() has seeded this run; flushState() resets it. */
    protected static bool $factoryFakerSeeded = false;

    /**
     * Seeds both Fakers that model factories draw from (the Faker\Generator behind
     * $this->faker and the per-locale one behind fake()) with examples.faker_seed,
     * once per run. Later calls are no-ops, so the sequence keeps advancing and
     * each model, every paginated row included, gets distinct values rather than
     * tripping unique indexes. Tools\RunState::flush() resets the guard before and
     * after each run. See docs/design/run-state.md.
     */
    protected function seedFactoryFaker(): void
    {
        if (self::$factoryFakerSeeded) {
            return;
        }

        $seed = $this->config->get('examples.faker_seed');
        if ($seed === null) {
            return;
        }

        app(\Faker\Generator::class)->seed($seed);
        fake()->seed($seed);
        self::$factoryFakerSeeded = true;
    }

    /**
     * Resets the using class's own copy of the guard. Trait statics are per
     * class, so each class that uses this trait needs its own call.
     */
    public static function flushState(): void
    {
        self::$factoryFakerSeeded = false;
    }

    /**
     * @param class-string $type
     * @param string[] $factoryStates
     * @param string[] $relations
     * @param string[] $withCount
     * @return null|Model
     */
    protected function getExampleModelFromFactoryCreate(string $type, array $factoryStates = [], array $relations = [], array $withCount = [], bool $quietly = false)
    {
        // $relations and $withCount name the same model relationships, so the
        // factory gets both and creates every relationship either one needs.
        $allRelations = array_unique(array_merge($relations, $withCount));

        $factory = Utils::getModelFactory($type, $factoryStates, $allRelations);

        $factory = $quietly ? Model::withoutEvents(fn () => $factory->create()) : $factory->create();

        return $factory->refresh()->load($relations)->loadCount($withCount);
    }

    /**
     * @param class-string $type
     * @param string[] $factoryStates
     * @return null|Model
     */
    protected function getExampleModelFromFactoryMake(string $type, array $factoryStates = [], array $relations = [])
    {
        $factory = Utils::getModelFactory($type, $factoryStates, $relations);

        return $factory->make();
    }

    /**
     * The first row in the table, unless the application picks the model.
     *
     * The bare query has no ORDER BY and cannot pick a row per endpoint, so
     * {@see \Ipsocode\Scribe\Scribe::resolveExampleModelUsing} lets an application
     * supply the model; a null from it falls through to the next configured
     * strategy, as an empty table would.
     *
     * @param class-string $type
     * @param string[] $relations
     * @param string[] $withCount
     * @return null|Model
     */
    protected function getExampleModelFromDatabaseFirst(string $type, array $relations = [], array $withCount = [])
    {
        if (is_callable(Globals::$__resolveExampleModelUsing)) {
            return call_user_func(Globals::$__resolveExampleModelUsing, $type, $relations, $withCount);
        }

        return $type::with($relations)->withCount($withCount)->first();
    }
}
