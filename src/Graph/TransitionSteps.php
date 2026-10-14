<?php

namespace RobertBoes\Interlock\Graph;

use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use RobertBoes\Interlock\Attributes\Authorise;
use RobertBoes\Interlock\Attributes\Guard;
use RobertBoes\Interlock\Definition\FluentTransition;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\UnauthorisedTransitionException;
use RobertBoes\Interlock\Support\PhpAttribute;
use RobertBoes\Interlock\Transition;

/**
 * The steps one application of a transition runs: a fluent transition's closures, or a transition object's methods.
 */
final readonly class TransitionSteps
{
    /**
     * @param  (Closure(?Authenticatable, Model): (bool|Response))|null  $authorisation
     * @param  list<CompiledGuard>  $guards
     */
    private function __construct(
        private ?Closure $authorisation,
        private array $guards,
        private ?Closure $apply,
        private ?Closure $afterCommit,
    ) {}

    public static function none(): self
    {
        return new self(null, [], null, null);
    }

    public static function fluent(FluentTransition $definition): self
    {
        $authorisation = is_string($definition->authorisation)
            ? self::gateAbility($definition->authorisation)
            : $definition->authorisation;

        return new self($authorisation, $definition->guards, $definition->applyStep, $definition->afterCommitStep);
    }

    public static function of(Transition $transition): self
    {
        $class = new ReflectionClass($transition);
        $ability = PhpAttribute::of($class, Authorise::class)?->ability;

        return new self(
            match (true) {
                method_exists($transition, 'authorise') => $transition->authorise(...),
                $ability !== null => self::gateAbility($ability),
                default => null,
            },
            self::guardMethodsOf($class, $transition),
            method_exists($transition, 'apply') ? $transition->apply(...) : null,
            method_exists($transition, 'afterCommit') ? $transition->afterCommit(...) : null,
        );
    }

    /**
     * @return list<string>
     */
    public function guardNames(): array
    {
        return array_map(fn(CompiledGuard $guard) => $guard->name, $this->guards);
    }

    public function withoutGuards(): self
    {
        return new self($this->authorisation, [], $this->apply, $this->afterCommit);
    }

    /**
     * @return Closure(?Authenticatable, Model): Response
     */
    private static function gateAbility(string $ability): Closure
    {
        return fn(?Authenticatable $actor, Model $model) => Container::getInstance()->make(Gate::class)->forUser($actor)->inspect($ability, $model);
    }

    /**
     * @param  ReflectionClass<Transition>  $class
     * @return list<CompiledGuard>
     */
    private static function guardMethodsOf(ReflectionClass $class, Transition $transition): array
    {
        return array_merge(...array_map(fn(ReflectionMethod $method) => self::guardsOf($method, $transition), $class->getMethods()));
    }

    /**
     * @return list<CompiledGuard> the method's guard, when it is one
     */
    private static function guardsOf(ReflectionMethod $method, Transition $transition): array
    {
        return array_map(
            fn(ReflectionAttribute $guard) => new CompiledGuard(Str::snake($method->getName()), $method->getClosure($transition), $guard->newInstance()->message),
            $method->getAttributes(Guard::class),
        );
    }

    /**
     * @throws UnauthorisedTransitionException when the declared authorisation denies the actor
     */
    public function authorise(Model $model, ?Authenticatable $actor, CompiledTransition $transition): void
    {
        $response = $this->authorisationResponse($model, $actor);

        if ($response->denied()) {
            throw UnauthorisedTransitionException::denied($model, $transition, $response);
        }
    }

    public function allows(Model $model, ?Authenticatable $actor): bool
    {
        return $this->authorisationResponse($model, $actor)->allowed();
    }

    private function authorisationResponse(Model $model, ?Authenticatable $actor): Response
    {
        if ($this->authorisation === null) {
            return Response::allow();
        }

        $result = ($this->authorisation)($actor, $model);

        if ($result instanceof Response) {
            return $result;
        }

        return $result ? Response::allow() : Response::deny();
    }

    /**
     * @throws FailedGuardException for the first guard that does not hold
     */
    public function checkGuards(Model $model, CompiledTransition $transition): void
    {
        $failed = $this->failedGuard($model);

        if ($failed !== null) {
            throw FailedGuardException::failed($model, $transition, $failed);
        }
    }

    public function guardsHold(Model $model): bool
    {
        return $this->failedGuard($model) === null;
    }

    private function failedGuard(Model $model): ?CompiledGuard
    {
        return array_find($this->guards, fn(CompiledGuard $guard) => ! ($guard->check)($model));
    }

    public function apply(Model $model): void
    {
        if ($this->apply !== null) {
            ($this->apply)($model);
        }
    }

    public function afterCommit(Model $model): void
    {
        if ($this->afterCommit !== null) {
            ($this->afterCommit)($model);
        }
    }
}
