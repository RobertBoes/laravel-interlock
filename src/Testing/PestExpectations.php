<?php

namespace RobertBoes\Interlock\Testing;

use Illuminate\Database\Eloquent\Model;
use Pest\Expectation;
use RobertBoes\Interlock\Interlock;

/**
 * Interlock's Pest expectations, registered by the service provider when tests run under Pest.
 */
final class PestExpectations
{
    public static function register(): void
    {
        (new Expectation(null))->extend('toHaveSoundStateMachines', function (): Expectation {
            /** @var Expectation<class-string<Model>|list<class-string<Model>>|array<class-string<Model>, mixed>> $this */
            Interlock::assertMachinesAreSound(...PestExpectations::modelsIn($this->value));

            return $this;
        });
    }

    /**
     * The models an expectation is about: one model class, a list of them, or the registry from Interlock::models().
     *
     * @param  class-string<Model>|list<class-string<Model>>|array<class-string<Model>, mixed>  $value
     * @return list<class-string<Model>>
     */
    public static function modelsIn(string|array $value): array
    {
        return match (true) {
            is_string($value) => [$value],
            array_is_list($value) => $value,
            default => array_keys($value),
        };
    }
}
