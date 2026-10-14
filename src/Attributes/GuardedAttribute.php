<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use RobertBoes\Interlock\Transition;

/**
 * An attribute other than the state attribute that only the named transitions of this state machine may write, such
 * as `#[GuardedAttribute('tracking_code', Ship::class)]`. Declared on the state machine, once per guarded attribute.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class GuardedAttribute
{
    /** @var list<string|class-string<Transition>> */
    public array $transitions;

    /**
     * @param  string|class-string<Transition>  ...$transitions  the transitions that may write it, by name or by class
     */
    public function __construct(public string $attribute, string ...$transitions)
    {
        $this->transitions = array_values($transitions);
    }
}
