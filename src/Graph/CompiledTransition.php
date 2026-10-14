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
     * @param  list<BackedEnum>  $leafFromStates  the from-states with each parent state expanded to the leaf states within it
     * @param  class-string<Transition>|null  $class  the transition class, or null for a fluent transition
     * @param  TransitionSteps  $fluentSteps  a fluent transition's steps; a class-based transition's come from its object
     * @param  (Closure(Builder<Model>): mixed)|null  $trigger  the condition the trigger adds to the model's query, or null when the transition has none
     * @param  bool  $triggerFansOut  whether a run of the trigger dispatches one job per chunk instead of applying the transition itself
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
        public bool $triggerFansOut = false,
    ) {}

    public function startsFrom(?BackedEnum $state): bool
    {
        return in_array($state, $this->leafFromStates, true);
    }

    /**
     * Whether the actor can apply this transition to the model right now: the model's stored state is a from-state, the
     * actor is authorised and its guards hold against the model, unsaved changes included.
     */
    public function isAvailable(Model $model, ?Authenticatable $actor): bool
    {
        if (! $this->startsFrom($model->getOriginal($this->stateAttribute))) {
            return false;
        }

        $steps = $this->stepsWithoutInput();

        return $steps->allows($model, $actor) && $steps->guardsHold($model);
    }

    /**
     * The names of the transition's guards, read without building a transition class, so without its input.
     *
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
     * The steps that can be checked before the transition's input is known. A transition class that takes input is
     * built without its constructor, so only its authorisation is checked; its guards may read the input, and run
     * once it is applied with it.
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

    /**
     * The steps to run: those of the given transition object, or, when the transition is applied by name, of a
     * transition object built without input, or the fluent transition's closures.
     */
    public function steps(?Transition $transition): TransitionSteps
    {
        if ($transition === null && $this->class !== null) {
            $transition = $this->build($this->class);
        }

        return $transition === null ? $this->fluentSteps : TransitionSteps::of($transition);
    }

    /**
     * Whether the transition class's constructor has required parameters. Those are its input, never built for it:
     * the container would build a model it takes as an empty one.
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
