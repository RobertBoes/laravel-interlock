<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use RobertBoes\Interlock\Exceptions\MissingTransitionInputException;
use RobertBoes\Interlock\Transition;

final readonly class CompiledTransition
{
    /**
     * @param  list<BackedEnum>  $fromStates  as declared, parent states included
     * @param  list<BackedEnum>  $leafFromStates
     * @param  class-string<Transition>|null  $class  null for a fluent transition
     * @param  (Closure(Builder<Model>): mixed)|null  $trigger
     * @param  bool  $triggerOnly  it has a trigger and declares no authorization, so no one may apply it by hand
     */
    public function __construct(
        public string $name,
        public string $stateAttribute,
        public array $fromStates,
        public BackedEnum $targetState,
        public array $leafFromStates,
        public ?string $class,
        private TransitionSteps $fluentSteps,
        public ?Closure $trigger,
        public bool $triggerFansOut,
        public bool $triggerOnly,
    ) {}

    public function startsFrom(?BackedEnum $state): bool
    {
        return in_array($state, $this->leafFromStates, true);
    }

    /**
     * Checks the stored state, but the guards see unsaved changes.
     */
    public function isAvailable(Model $model, ?Authenticatable $actor): bool
    {
        if ($this->triggerOnly || ! $this->startsFrom($model->getOriginal($this->stateAttribute))) {
            return false;
        }

        $steps = $this->stepsWithoutInput();

        return $steps->allows($model, $actor) && $steps->guardsHold($model);
    }

    /**
     * @return list<string>
     */
    public function guardNames(): array
    {
        $steps = $this->class === null
            ? $this->fluentSteps
            : TransitionSteps::of(new ReflectionClass($this->class)->newInstanceWithoutConstructor());

        return $steps->guardNames();
    }

    /**
     * Guards may read the input, so a transition class that takes input is only checked for authorization.
     */
    private function stepsWithoutInput(): TransitionSteps
    {
        if ($this->class === null) {
            return $this->fluentSteps;
        }

        return self::takesInput($this->class)
            ? TransitionSteps::of(new ReflectionClass($this->class)->newInstanceWithoutConstructor())->withoutGuards()
            : TransitionSteps::of(new $this->class());
    }

    public function steps(?Transition $transition): TransitionSteps
    {
        if ($transition !== null) {
            return TransitionSteps::of($transition);
        }

        if ($this->class === null) {
            return $this->fluentSteps;
        }

        return TransitionSteps::of($this->build($this->class));
    }

    /**
     * Required constructor parameters are input, never resolved from the container: it would build a model as an empty one.
     *
     * @param  class-string<Transition>  $class
     */
    public static function takesInput(string $class): bool
    {
        return (new ReflectionClass($class)->getConstructor()?->getNumberOfRequiredParameters() ?? 0) > 0;
    }

    /**
     * @param  class-string<Transition>  $class
     */
    private function build(string $class): Transition
    {
        if (self::takesInput($class)) {
            throw MissingTransitionInputException::appliedByName($this, $class);
        }

        return new $class();
    }
}
