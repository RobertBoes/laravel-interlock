<?php

use Illuminate\Support\Facades\Artisan;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;

beforeEach(fn() => config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Orders', __DIR__ . '/Fixtures/Expenses']]));

function viz(array $parameters = []): string
{
    expect(Artisan::call('interlock:signalbox', ['--viz' => true, ...$parameters]))->toBe(0);

    return Artisan::output();
}

function vizConfig(array $parameters = []): array
{
    return json_decode(str_replace('Paste it into https://sketch.stately.ai and click Update.', '', viz($parameters)), true);
}

it('exports a machine as its XState config, and says where to paste it', function () {
    Artisan::call('interlock:signalbox', ['machine' => OrderStatusMachine::class, '--format' => 'xstate']);
    $xstate = json_decode(Artisan::output(), true);

    expect(vizConfig(['machine' => OrderStatusMachine::class]))->toBe($xstate)
        ->and(viz(['machine' => OrderStatusMachine::class]))->toContain('Paste it into https://sketch.stately.ai and click Update.');
});

it('exports the only registered machine on its own', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Exports']]);

    expect(vizConfig())->toHaveKey('id', 'ClaimStatusMachine');
});

it('exports every machine as a region of one parallel machine when none is given', function () {
    $config = vizConfig();

    expect($config)->toMatchArray(['id' => 'StateMachines', 'type' => 'parallel'])
        ->and(array_keys($config['states']))->toBe(['ExpenseStatusMachine', 'OrderStatusMachine', 'PaymentStatusMachine'])
        ->and($config['states']['OrderStatusMachine'])->toMatchArray(['id' => 'OrderStatusMachine', 'initial' => 'pending'])
        ->and($config['states']['OrderStatusMachine']['states']['preparing']['states']['label_created']['on']['ship']['target'])->toBe('#OrderStatusMachine.shipped');
});

it('keys events by signal', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/SupportTickets']]);

    expect(vizConfig()['states']['closed']['on'])->toBe([
        'reply' => ['target' => '#TicketStatusMachine.active.open', 'meta' => ['transition' => 'reopen']],
    ]);
});

it('refuses to combine machines that share a class name', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Orders', __DIR__ . '/Fixtures/LegacyOrders']]);

    $this->artisan('interlock:signalbox', ['--viz' => true])
        ->expectsOutputToContain('machine IDs are class names without their namespace')
        ->assertFailed();
});

it('only exports XState', function (string $format) {
    $this->artisan('interlock:signalbox', ['--viz' => true, '--format' => $format])
        ->expectsOutputToContain("--viz exports XState for Stately Sketch, not [{$format}].")
        ->assertFailed();
})->with(['mermaid', 'xstate-ts']);
