<?php

namespace RobertBoes\Interlock\Attributes;

use Attribute;

/**
 * The method receives the model, unsaved changes included, and returns whether the guard holds. The guard is named
 * after the method in snake case, so inStock() is in_stock.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Guard
{
    public function __construct(public string $message) {}
}
