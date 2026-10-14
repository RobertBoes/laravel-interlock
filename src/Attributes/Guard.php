<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * Marks a method of a class-based transition as a guard: a condition on the model that must hold for the transition
 * to apply. The method receives the model, unsaved changes included, and returns whether the condition holds. When
 * it does not, the transition throws FailedGuardException with the message, which renders as 422. The guard is
 * named after the method in snake case, so inStock() is in_stock.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Guard
{
    public function __construct(public string $message) {}
}
