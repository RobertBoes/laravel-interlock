<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\CompiledGuard;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * A guard of the transition did not hold: "not now". Its message is the guard's own, and it renders as 422.
 */
final class FailedGuardException extends RuntimeException implements InterlockException, HttpExceptionInterface
{
    private function __construct(
        string $message,
        public readonly Model $model,
        public readonly string $transition,
        public readonly string $guard,
    ) {
        parent::__construct($message);
    }

    public static function failed(Model $model, CompiledTransition $transition, CompiledGuard $guard): self
    {
        return new self($guard->message, $model, $transition->name, $guard->name);
    }

    public function getStatusCode(): int
    {
        return 422;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return [];
    }
}
