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
     * @param null|ReflectionFunctionAbstract $transformationMethod A method which has the model as its first parameter. Useful if the `$type` is empty.
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
                // Ladies and gentlemen, we have a type!
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

        // Seed the model-factory faker ONCE per generation (guarded below): a fixed
        // seed makes the run reproducible, and letting the sequence advance across
        // every model — including the repeated calls that build a paginated
        // collection — keeps factory-generated unique values (usernames, codes…)
        // distinct. Reseeding per call/model instead would reset each collection row
        // to the same values and trip unique indexes.
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

    /** Guards seedFactoryFaker() to one reseed per scribe:generate process. */
    protected static bool $factoryFakerSeeded = false;

    /**
     * Seed — once per generation — the faker instances model factories draw from,
     * so factory-generated example models are reproducible across docs runs.
     * Factories use two distinct instances: the coroutine-scoped Faker\Generator
     * behind a factory's $this->faker, and the per-locale singleton behind the
     * fake() helper — both must be reset. The static guard makes this a no-op after
     * the first model so the faker sequence keeps advancing — that keeps each model
     * (including the repeated calls that build a paginated collection) distinct,
     * avoiding unique-index clashes. Process-scoped: scribe:generate always runs as
     * its own CLI process, and this trait is never used at runtime.
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
     * Flush this using-class's own copy of the guard. Trait statics are
     * per-class, not shared, so each class that uses this trait (and doesn't
     * override flushState() itself) gets its own reset via `self::`.
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
        // Since $relations and $withCount refer to the same underlying relationships in the model,
        // combining them ensures that all required relationships are initialized when passed to the factory.
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
     * The first row in the table, optionally chosen by the application.
     *
     * The bare query below has no ORDER BY, so which row it returns is up to storage order, and it
     * has no way to express "the row that should stand for THIS endpoint". Applications that commit
     * their generated spec need both, so {@see \Ipsocode\Scribe\Scribe::resolveExampleModelUsing}
     * lets them supply the query themselves; returning null there falls through to the next
     * configured strategy, exactly as an empty table would.
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
