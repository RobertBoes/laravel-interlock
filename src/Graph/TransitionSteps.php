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
use RobertBoes\Interlock\Attributes\Authorize;
use RobertBoes\Interlock\Attributes\Guard;
use RobertBoes\Interlock\Definition\FluentTransition;
use RobertBoes\Interlock\Exceptions\FailedGuardException;
use RobertBoes\Interlock\Exceptions\UnauthorizedTransitionException;
use RobertBoes\Interlock\Support\PhpAttribute;
use RobertBoes\Interlock\Transition;

final readonly class TransitionSteps
{
    /**
     * @param  (Closure(?Authenticatable, Model): (bool|Response))|null  $authorization
     * @param  list<CompiledGuard>  $guards
     */
    private function __construct(
        private ?Closure $authorization,
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
        $authorization = is_string($definition->authorization)
            ? self::gateAbility($definition->authorization)
            : $definition->authorization;

        return new self($authorization, $definition->guards, $definition->applyStep, $definition->afterCommitStep);
    }

    public static function of(Transition $transition): self
    {
        $class = new ReflectionClass($transition);
        $ability = PhpAttribute::of($class, Authorize::class)?->ability;

        return new self(
            match (true) {
                method_exists($transition, 'authorize') => $transition->authorize(...),
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
        return new self($this->authorization, [], $this->apply, $this->afterCommit);
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
     * @return list<CompiledGuard>
     */
    private static function guardsOf(ReflectionMethod $method, Transition $transition): array
    {
        return array_map(
            fn(ReflectionAttribute $guard) => new CompiledGuard(Str::snake($method->getName()), $method->getClosure($transition), $guard->newInstance()->message),
            $method->getAttributes(Guard::class),
        );
    }

    /**
     * @throws UnauthorizedTransitionException
     */
    public function authorize(Model $model, ?Authenticatable $actor, CompiledTransition $transition): void
    {
        $response = $this->authorizationResponse($model, $actor);

        if ($response->denied()) {
            throw UnauthorizedTransitionException::denied($model, $transition, $response);
        }
    }

    public function allows(Model $model, ?Authenticatable $actor): bool
    {
        return $this->authorizationResponse($model, $actor)->allowed();
    }

    private function authorizationResponse(Model $model, ?Authenticatable $actor): Response
    {
        if ($this->authorization === null) {
            return Response::allow();
        }

        $result = ($this->authorization)($actor, $model);

        if ($result instanceof Response) {
            return $result;
        }

        return $result ? Response::allow() : Response::deny();
    }

    /**
     * @throws FailedGuardException
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
