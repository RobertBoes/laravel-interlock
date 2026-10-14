<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use RobertBoes\Interlock\Exceptions\InvalidDefinitionException;
use RobertBoes\Interlock\HasStateMachines;
use RobertBoes\Interlock\Interlock;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\ImportedPost;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\ImportedPostStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\Post;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Discovery\Comment;
use RobertBoes\Interlock\Tests\Fixtures\Discovery\PackagedPost;
use RobertBoes\Interlock\Tests\MisdeclaredModels\PostWithoutHasStateMachines;

it('discovers the interlocked models in the configured paths', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts']]);

    expect(Interlock::models())->toEqual([
        Post::class => ['status' => PostStatusMachine::class],
        ImportedPost::class => ['status' => ImportedPostStatusMachine::class],
    ]);
});

it('scans the configured paths recursively', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures']]);

    expect(Interlock::models())->toHaveKey(Post::class);
});

it('scans the app\'s models directory by default', function () {
    expect(config('interlock.discovery.paths'))->toBe([app_path('Models')]);
});

it('skips a configured path that does not exist', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Missing']]);

    expect(Interlock::models())->toBe([]);
});

it('includes the models registered in config', function () {
    config([
        'interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts'],
        'interlock.models' => [PackagedPost::class],
    ]);

    expect(Interlock::models())->toEqual([
        Post::class => ['status' => PostStatusMachine::class],
        ImportedPost::class => ['status' => ImportedPostStatusMachine::class],
        PackagedPost::class => ['status' => PostStatusMachine::class],
    ]);
});

it('includes the models registered from a service provider', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts']]);

    app()->register(new class (app()) extends ServiceProvider {
        public function boot(): void
        {
            Interlock::registerModels(PackagedPost::class);
        }
    });

    expect(Interlock::models())->toEqual([
        Post::class => ['status' => PostStatusMachine::class],
        ImportedPost::class => ['status' => ImportedPostStatusMachine::class],
        PackagedPost::class => ['status' => PostStatusMachine::class],
    ]);
});

it('rejects a registered model without a state machine', function () {
    config(['interlock.models' => [Comment::class]]);

    expect(fn() => Interlock::models())->toThrow(
        InvalidDefinitionException::class,
        Comment::class . ' is registered with Interlock, but has no #[UseStateMachine] attribute.',
    );
});

it('rejects a model that declares a state machine without using HasStateMachines', function (array $config) {
    config($config);

    expect(fn() => Interlock::models())->toThrow(
        InvalidDefinitionException::class,
        PostWithoutHasStateMachines::class . ' declares #[UseStateMachine], but does not use the ' . HasStateMachines::class . ' trait, so none of its machines is enforced and its triggers cannot run.',
    );
})->with([
    'discovered' => [['interlock.discovery.paths' => [__DIR__ . '/MisdeclaredModels']]],
    'registered' => [['interlock.discovery.paths' => [], 'interlock.models' => [PostWithoutHasStateMachines::class]]],
]);

describe('the registry cache', function () {
    beforeEach(fn() => config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts']]));

    afterEach(fn() => Artisan::call('interlock:clear'));

    it('is read instead of scanning once interlock:cache has written it', function () {
        $this->artisan('interlock:cache')->assertSuccessful();

        config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Discovery']]);

        expect(Interlock::models())->toEqual([
            Post::class => ['status' => PostStatusMachine::class],
            ImportedPost::class => ['status' => ImportedPostStatusMachine::class],
        ]);
    });

    it('is removed by interlock:clear', function () {
        $this->artisan('interlock:cache')->assertSuccessful();
        $this->artisan('interlock:clear')->assertSuccessful();

        config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Discovery']]);

        expect(Interlock::models())->toBe([
            PackagedPost::class => ['status' => PostStatusMachine::class],
        ]);
    });

    it('is written by optimize', function () {
        $this->artisan('optimize', ['--except' => 'config,events,routes,views'])->assertSuccessful();

        config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Discovery']]);

        expect(Interlock::models())->toEqual([
            Post::class => ['status' => PostStatusMachine::class],
            ImportedPost::class => ['status' => ImportedPostStatusMachine::class],
        ]);
    });

    it('is removed by optimize:clear', function () {
        $this->artisan('interlock:cache')->assertSuccessful();
        $this->artisan('optimize:clear', ['--except' => 'config,cache,compiled,events,routes,views'])->assertSuccessful();

        config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Discovery']]);

        expect(Interlock::models())->toBe([
            PackagedPost::class => ['status' => PostStatusMachine::class],
        ]);
    });

    it('still includes the models registered from a service provider', function () {
        $this->artisan('interlock:cache')->assertSuccessful();

        Interlock::registerModels(PackagedPost::class);

        expect(Interlock::models())->toEqual([
            Post::class => ['status' => PostStatusMachine::class],
            ImportedPost::class => ['status' => ImportedPostStatusMachine::class],
            PackagedPost::class => ['status' => PostStatusMachine::class],
        ]);
    });
});
