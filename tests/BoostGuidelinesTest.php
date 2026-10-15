<?php

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Pest\Expectation;
use RobertBoes\Interlock\Definition\FluentTransition;
use RobertBoes\Interlock\HasStateScopes;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Scopes\InState;
use RobertBoes\Interlock\Scopes\NotInState;
use RobertBoes\Interlock\StateMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\Order;

/**
 * Laravel Boost copies these guidelines into the AI instructions of every app that installs the package, so a name
 * they teach that no longer exists would have assistants write code that fails. A rename fails here instead.
 */
function boostGuidelinesPath(): string
{
    return __DIR__ . '/../resources/boost/guidelines/core.blade.php';
}

function boostGuidelines(): string
{
    return file_get_contents(boostGuidelinesPath());
}

/**
 * @return list<string>
 */
function namedInBoostGuidelines(string $pattern): array
{
    preg_match_all($pattern, boostGuidelines(), $matches);

    return array_values(array_unique($matches[1]));
}

it('ships the guidelines where Boost looks for them, and renders them with Blade', function () {
    expect(boostGuidelinesPath())->toBeFile();

    $rendered = Blade::render(boostGuidelines());

    expect($rendered)
        ->toContain('## Laravel Interlock', '#[UseStateMachine(', '<code-snippet')
        ->not->toContain('@verbatim', '@endverbatim');
});

it('names only Artisan commands the package registers', function () {
    $commands = namedInBoostGuidelines('/\b(interlock:[a-z]+)\b/');

    expect($commands)->not->toBeEmpty()
        ->and(array_keys(Artisan::all()))->toContain(...$commands);
});

it('imports only classes that exist', function () {
    $imports = namedInBoostGuidelines('/^use ([\w\\\\]+);$/m');

    expect($imports)->not->toBeEmpty();

    foreach ($imports as $import) {
        expect(class_exists($import) || interface_exists($import) || trait_exists($import))->toBeTrue("The guidelines import [{$import}], which does not exist.");
    }
});

it('names only attributes and exceptions the package has', function () {
    $attributes = namedInBoostGuidelines('/#\[(\w+)/');
    $exceptions = namedInBoostGuidelines('/`(\w+Exception)`/');

    expect($attributes)->not->toBeEmpty()
        ->and($exceptions)->not->toBeEmpty();

    foreach ($attributes as $attribute) {
        expect(class_exists("RobertBoes\\Interlock\\Attributes\\{$attribute}"))->toBeTrue("The guidelines name the attribute #[{$attribute}], which does not exist.");
    }

    foreach ($exceptions as $exception) {
        expect(class_exists("RobertBoes\\Interlock\\Exceptions\\{$exception}"))->toBeTrue("The guidelines name [{$exception}], which does not exist.");
    }
});

it('calls only methods the Interlock class has', function () {
    $methods = namedInBoostGuidelines('/\bInterlock::(\w+)\(/');

    expect($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        expect(method_exists(Interlock::class, $method))->toBeTrue("The guidelines call Interlock::{$method}(), which does not exist.");
    }
});

it('calls only methods that interlocked models, machines, fluent transitions, queries and expectations have', function () {
    $methods = namedInBoostGuidelines('/->(\w+)\(/');

    expect($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        $exists = Expectation::hasMethod($method)
            || collect([Order::class, StateMachine::class, FluentTransition::class, Builder::class, FormRequest::class])
                ->contains(fn(string $class) => method_exists($class, $method));

        expect($exists)->toBeTrue("The guidelines call ->{$method}(), which no interlocked model, machine, fluent transition, query or expectation has.");
    }
});

it('names only state scopes the package has', function () {
    $scopes = namedInBoostGuidelines('/`(where\w+State\w*)\(\)`/');

    expect($scopes)->toEqualCanonicalizing(['whereInState', 'whereNotInState', 'whereInFromStateOf']);

    foreach ($scopes as $scope) {
        expect(method_exists(HasStateScopes::class, 'scope' . ucfirst($scope)))->toBeTrue("The guidelines name the scope {$scope}(), which does not exist.");
    }

    foreach ([InState::class, NotInState::class] as $scope) {
        $parameters = array_map(fn(ReflectionParameter $parameter) => $parameter->getName(), (new ReflectionMethod($scope, '__construct'))->getParameters());

        expect($parameters)->toContain('stateAttribute');
    }
});

it('says plainly where enforcement stops', function () {
    expect(boostGuidelines())->toContain('`DB::table()`', '`toBase()`', 'raw SQL');
});
