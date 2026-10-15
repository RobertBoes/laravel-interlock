<?php

namespace RobertBoes\Interlock\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use RobertBoes\Interlock\Graph\CompiledTransition;

/**
 * Renders as 403, or with the status a denying Gate response gave.
 */
final class UnauthorizedTransitionException extends AuthorizationException implements InterlockException
{
    private function __construct(
        string $message,
        mixed $code,
        public readonly Model $model,
        public readonly string $transition,
    ) {
        parent::__construct($message, $code);
    }

    public static function denied(Model $model, CompiledTransition $transition, Response $response): self
    {
        $exception = new self(
            $response->message() ?? sprintf('You may not apply transition [%s].', $transition->name),
            $response->code(),
            $model,
            $transition->name,
        );

        return $exception->setResponse($response)->withStatus($response->status());
    }
}
