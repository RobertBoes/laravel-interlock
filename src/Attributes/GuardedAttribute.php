<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;
use RobertBoes\Interlock\Transition;

/**
 * An attribute that only the given transitions, by name or by class, may write.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
final readonly class GuardedAttribute
{
    /** @var list<string|class-string<Transition>> */
    public array $transitions;

    /**
     * @param  string|class-string<Transition>  ...$transitions
     */
    public function __construct(public string $attribute, string ...$transitions)
    {
        $this->transitions = array_values($transitions);
    }
}
