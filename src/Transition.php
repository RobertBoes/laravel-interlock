<?php

namespace RobertBoes\Interlock;

use Illuminate\Support\Str;

/**
 * A transition declared as its own class, for a transition that takes input or runs steps. Declare its from-states
 * with #[FromStates(...)] and its target state with #[TargetState(...)], list the class in the machine's
 * transitions(), and apply it with `$model->transition(new Ship($trackingCode))`.
 *
 * It may declare who may apply it, with either an `authorise(?Authenticatable $actor, $model)` method returning a bool
 * or a Gate response, or #[Authorise('ability')] on the class for a Gate ability. Without either, every actor may apply
 * it. Its guards are methods marked #[Guard('message')] that receive the model and return whether the condition holds.
 * Authorisation is checked before the transaction begins, guards inside it, before `apply`.
 *
 * For available transitions, a transition that takes input is built without calling its constructor, and only its
 * authorisation is checked, so `authorise()` must not read the input; guards may, as they run once it is applied.
 *
 * It may declare a trigger, a static `trigger(Builder $query)` method that constrains the model's query, such as
 * `$query->where('publish_at', '<=', now())`. Its from-states are added to it, and every minute the scheduled
 * interlock:triggers command applies it by name, on behalf of no one, to each model that matches. A transition that
 * takes input cannot be built by name, so it cannot be triggered.
 *
 * It may define either of these steps, each receiving the model:
 * - `apply($model)` runs inside the transition's transaction, after the model's state attribute is set to the target
 *   state and before the compare-and-swap write. Attributes it sets are saved in the same update, and rows it writes
 *   roll back when the transition fails, stale state included.
 * - `afterCommit($model)` runs once the transition has committed, before Transitioned is dispatched. Send mail,
 *   make HTTP calls and dispatch queued work here.
 */
abstract class Transition
{
    /**
     * The name the transition is applied by and recorded under. Defaults to the class name in snake case, so Ship is
     * ship and CreateLabel is create_label; override it to choose another.
     */
    public static function name(): string
    {
        return Str::snake(class_basename(static::class));
    }
}
