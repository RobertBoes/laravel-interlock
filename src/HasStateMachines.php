<?php

namespace RobertBoes\Interlock;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use RobertBoes\Interlock\Events\Transitioned;
use RobertBoes\Interlock\Exceptions\IllegalTransitionException;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\Graph\ModelGraph;

/**
 * @mixin Model
 */
trait HasStateMachines
{
    public function transition(string $name): static
    {
        $transition = $this->interlockGraph()->transition($name);
        $fromState = $this->getOriginal($transition->stateAttribute);

        if (! $transition->startsFrom($fromState)) {
            throw IllegalTransitionException::currentStateNotAFromState($this, $transition, $fromState);
        }

        $unsavedState = $this->getAttribute($transition->stateAttribute);
        $this->setAttribute($transition->stateAttribute, $transition->targetState);

        if (! $this->save()) {
            $this->setAttribute($transition->stateAttribute, $unsavedState);

            return $this;
        }

        Event::dispatch(new Transitioned($this, $transition->name, $fromState, $transition->targetState));

        return $this;
    }

    private function interlockGraph(): ModelGraph
    {
        return Container::getInstance()->make(GraphRepository::class)->for($this);
    }
}
