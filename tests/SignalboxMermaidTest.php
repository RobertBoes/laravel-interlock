<?php

use Illuminate\Support\Facades\Artisan;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\ImportedPostStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Discovery\PackagedPost;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatusMachine;

beforeEach(fn() => config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts', __DIR__ . '/Fixtures/Orders']]));

function signalbox(array $parameters = []): string
{
    expect(Artisan::call('interlock:signalbox', ['--format' => 'mermaid', ...$parameters]))->toBe(0);

    return Artisan::output();
}

it('exports a machine as a Mermaid state diagram', function () {
    expect(signalbox(['machine' => PostStatusMachine::class]))->toMatchSnapshot();
});

it('draws parent states as composite states, with edges from parent states', function () {
    expect(signalbox(['machine' => OrderStatusMachine::class]))->toMatchSnapshot();
});

it('exports only the named machine of a model with several', function () {
    expect(signalbox(['machine' => PaymentStatusMachine::class]))->toMatchSnapshot();
});

it('starts every initial state from the start marker', function () {
    expect(signalbox(['machine' => ImportedPostStatusMachine::class]))->toMatchSnapshot();
});

it('exports every registered machine once, as Markdown, when no machine is given', function () {
    config([
        'interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts'],
        'interlock.models' => [PackagedPost::class],
    ]);

    expect(signalbox())->toMatchSnapshot();
});

it('writes the export to a file instead of stdout when given an output path', function () {
    $path = sys_get_temp_dir() . '/interlock-signalbox-' . uniqid() . '.mmd';

    try {
        $this->artisan('interlock:signalbox', ['machine' => OrderStatusMachine::class, '--output' => $path])
            ->expectsOutputToContain("Exported to [{$path}].")
            ->doesntExpectOutputToContain('stateDiagram-v2')
            ->assertSuccessful();

        expect(file_get_contents($path))->toBe(signalbox(['machine' => OrderStatusMachine::class]));
    } finally {
        @unlink($path);
    }
});

it('fails for a machine that no registered model uses', function () {
    $this->artisan('interlock:signalbox', ['machine' => 'App\StateMachines\InvoiceStatusMachine'])
        ->expectsOutputToContain('No registered model uses the state machine [App\StateMachines\InvoiceStatusMachine].')
        ->assertFailed();
});

it('fails for a format it cannot export', function () {
    $this->artisan('interlock:signalbox', ['--format' => 'graphviz'])
        ->expectsOutputToContain('Interlock cannot export the format [graphviz]. The formats are: mermaid, xstate, xstate-ts.')
        ->assertFailed();
});
