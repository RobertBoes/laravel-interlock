<?php

namespace RobertBoes\Interlock\Graph;

use BackedEnum;
use Illuminate\Container\Container;
use Illuminate\Contracts\Container\BindingResolutionException;
use RobertBoes\Interlock\Exceptions\MissingTransitionInputException;
use RobertBoes\Interlock\Transition;

final readonly class CompiledTransition
{
    /**
     * @param  list<BackedEnum>  $fromStates  as declared, parent states included
     * @param  list<BackedEnum>  $leafFromStates  the from-states with each parent state expanded to the leaf states within it
     * @param  class-string<Transition>|null  $class  the transition class, or null for a fluent transition
     * @param  TransitionSteps  $fluentSteps  a fluent transition's steps; a class-based transition's come from its object
     */
    public function __construct(
        public string $name,
        public string $stateAttribute,
        public array $fromStates,
        public BackedEnum $targetState,
        public array $leafFromStates,
        public ?string $class,
        private TransitionSteps $fluentSteps,
    ) {}

    public function startsFrom(?BackedEnum $state): bool
    {
        return in_array($state, $this->leafFromStates, true);
    }

    /**
     * The steps to run: those of the given transition object, or, when the transition is applied by name, of a
     * transition object the container builds, or the fluent transition's closures.
     */
    public function steps(?Transition $transition): TransitionSteps
    {
        if ($transition === null && $this->class !== null) {
            $transition = $this->build($this->class);
        }

        return $transition === null ? $this->fluentSteps : TransitionSteps::of($transition);
    }

    /**
     * @param  class-string<Transition>  $class
     */
    private function build(string $class): Transition
    {
        try {
            return Container::getInstance()->make($class);
        } catch (BindingResolutionException $exception) {
            throw MissingTransitionInputException::appliedByName($this, $class, $exception);
        }
    }
}
