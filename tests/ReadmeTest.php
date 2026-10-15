<?php

use Illuminate\Support\Facades\Artisan;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\InterlockServiceProvider;

/**
 * The README is the package's manual, so what it names must exist: a renamed command, tag, class or config key fails
 * here instead of in a reader's app.
 */
function readme(): string
{
    return file_get_contents(__DIR__ . '/../README.md');
}

/**
 * @return list<string>
 */
function namedInReadme(string $pattern): array
{
    preg_match_all($pattern, readme(), $matches);

    return array_values(array_unique($matches[1]));
}

it('publishes the config and the history migration under the tags it names', function () {
    $tags = namedInReadme('/--tag=([\w-]+)/');

    expect($tags)->toEqualCanonicalizing(['interlock-config', 'interlock-migrations']);

    foreach ($tags as $tag) {
        expect(InterlockServiceProvider::pathsToPublish(InterlockServiceProvider::class, $tag))->not->toBeEmpty();
    }
});

it('names only Artisan commands the package registers', function () {
    $commands = namedInReadme('/\b(interlock:[a-z]+(?::[a-z]+)*)\b/');

    expect($commands)->toEqualCanonicalizing(['interlock:cache', 'interlock:clear', 'interlock:signalbox', 'interlock:triggers', 'interlock:triggers:check'])
        ->and(array_keys(Artisan::all()))->toContain(...$commands);
});

it('imports only classes the package has', function () {
    $imports = namedInReadme('/^use (RobertBoes\\\\Interlock\\\\[\w\\\\]+);$/m');

    expect($imports)->not->toBeEmpty();

    foreach ($imports as $import) {
        expect(class_exists($import) || interface_exists($import) || trait_exists($import))->toBeTrue("The README imports [{$import}], which does not exist.");
    }
});

it('calls only methods the Interlock class has', function () {
    $methods = namedInReadme('/\bInterlock::(\w+)\(/');

    expect($methods)->not->toBeEmpty();

    foreach ($methods as $method) {
        expect(method_exists(Interlock::class, $method))->toBeTrue("The README calls Interlock::{$method}(), which does not exist.");
    }
});

it('names only config keys the package has', function () {
    $keys = namedInReadme('/`(interlock\.[a-z_.]+)`/');

    expect($keys)->not->toBeEmpty();

    foreach ($keys as $key) {
        expect(config()->has($key))->toBeTrue("The README names the config key [{$key}], which does not exist.");
    }
});

it('says plainly where enforcement stops', function () {
    expect(readme())->toContain('`DB::table()`', '`toBase()`', 'raw SQL');
});
