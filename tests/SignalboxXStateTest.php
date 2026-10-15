<?php

use Illuminate\Support\Facades\Artisan;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\ImportedPostStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\BlogPosts\PostStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Contracts\FinanceApprovalMachine;
use RobertBoes\Interlock\Tests\Fixtures\Contracts\LegalApprovalMachine;
use RobertBoes\Interlock\Tests\Fixtures\Expenses\ExpenseStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Exports\ClaimStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\LegacyOrders\OrderStatusMachine as LegacyOrderStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\OrderStatusMachine;
use RobertBoes\Interlock\Tests\Fixtures\Orders\PaymentStatusMachine;

beforeEach(fn() => config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/BlogPosts', __DIR__ . '/Fixtures/Orders']]));

function xstate(array $parameters = [], string $format = 'xstate'): string
{
    expect(Artisan::call('interlock:signalbox', ['--format' => $format, ...$parameters]))->toBe(0);

    return Artisan::output();
}

it('exports a machine as an XState machine config, with transitions as events and final states marked final', function () {
    expect(json_decode(xstate(['machine' => PostStatusMachine::class]), true))->toBe([
        'id' => 'PostStatusMachine',
        'initial' => 'draft',
        'states' => [
            'draft' => [
                'on' => [
                    'submit' => ['target' => 'ready'],
                ],
            ],
            'ready' => [
                'on' => [
                    'schedule' => ['target' => 'scheduled'],
                    'publish' => ['target' => 'published', 'meta' => ['trigger' => true]],
                    'retract' => ['target' => 'draft'],
                ],
            ],
            'scheduled' => [
                'on' => [
                    'publish' => ['target' => 'published', 'meta' => ['trigger' => true]],
                    'retract' => ['target' => 'draft'],
                    'reschedule' => ['target' => 'scheduled'],
                ],
            ],
            'published' => [
                'type' => 'final',
            ],
        ],
    ]);
});

it('nests child states within their parent state, and targets states at another level by ID', function () {
    expect(json_decode(xstate(['machine' => OrderStatusMachine::class]), true))->toBe([
        'id' => 'OrderStatusMachine',
        'initial' => 'pending',
        'states' => [
            'pending' => [
                'on' => [
                    'prepare' => ['target' => '#OrderStatusMachine.preparing.packing'],
                    'cancel' => ['target' => 'cancelled', 'meta' => ['trigger' => true]],
                ],
            ],
            'preparing' => [
                'initial' => 'packing',
                'on' => [
                    'cancel' => ['target' => 'cancelled', 'meta' => ['trigger' => true]],
                ],
                'states' => [
                    'packing' => [
                        'on' => [
                            'create_label' => ['target' => 'label_created'],
                        ],
                    ],
                    'label_created' => [
                        'on' => [
                            'ship' => ['target' => '#OrderStatusMachine.shipped'],
                        ],
                    ],
                ],
            ],
            'shipped' => [
                'on' => [
                    'deliver' => ['target' => 'delivered'],
                ],
            ],
            'delivered' => ['type' => 'final'],
            'cancelled' => ['type' => 'final'],
        ],
    ]);
});

it('names each guard of a transition, fluent or a guard method, without its check, message or authorization', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Expenses']]);

    $export = xstate(['machine' => ExpenseStatusMachine::class]);

    expect(json_decode($export, true)['states'])->toBe([
        'draft' => [
            'on' => [
                'submit' => ['target' => 'submitted', 'guard' => 'has_amount', 'meta' => ['trigger' => true]],
            ],
        ],
        'submitted' => [
            'on' => [
                'approve' => ['target' => 'approved', 'guard' => 'within_approval_limit'],
                'reject' => ['target' => 'rejected', 'guard' => 'has_rejection_reason'],
                'withdraw' => ['target' => 'draft'],
            ],
        ],
        'approved' => [
            'on' => [
                'pay' => ['target' => 'paid', 'guard' => 'has_reference'],
            ],
        ],
        'rejected' => ['type' => 'final'],
        'paid' => ['type' => 'final'],
    ])
        ->and($export)
        ->not->toContain('An expense needs an amount')
        ->not->toContain('withdraw-expense')
        ->not->toContain('pay-expense')
        ->not->toContain('created_at');
});

it('names several guards of a transition together as one guard', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Exports']]);

    expect(json_decode(xstate(['machine' => ClaimStatusMachine::class]), true)['states']['1']['on']['settle'])->toBe([
        'target' => '2',
        'guard' => 'has_assessment and within_policy_limit',
    ]);
});

it('keys states of an integer-backed enum by their value as a string, in an object', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Exports']]);

    $config = json_decode(xstate(['machine' => ClaimStatusMachine::class]));

    expect($config->initial)->toBe('0')
        ->and($config->states)->toBeObject()->toHaveProperties(['0', '1', '2'])
        ->and($config->states->{'0'}->on->assess->target)->toBe('1');
});

it('starts from the first of several initial states, and lists them all in the metadata', function () {
    expect(json_decode(xstate(['machine' => ImportedPostStatusMachine::class]), true))
        ->toMatchArray([
            'id' => 'ImportedPostStatusMachine',
            'initial' => 'draft',
            'meta' => ['initialStates' => ['draft', 'ready']],
        ]);
});

it('writes the same config as TypeScript, with literal types for the state and event names', function () {
    $typescript = xstate(['machine' => OrderStatusMachine::class], 'xstate-ts');

    expect($typescript)
        ->toStartWith('export const orderStatusMachine = {')
        ->toContain('export type OrderStatusMachineState = "pending" | "packing" | "label_created" | "shipped" | "delivered" | "cancelled";')
        ->toContain('export type OrderStatusMachineEvent = { type: "prepare" } | { type: "create_label" } | { type: "ship" } | { type: "deliver" } | { type: "cancel" };');

    $config = str($typescript)->after('export const orderStatusMachine = ')->before(' as const;')->toString();

    expect(json_decode($config, true))->toBe(json_decode(xstate(['machine' => OrderStatusMachine::class]), true));
});

it('exports every registered machine once, as one JSON object keyed by machine ID, when no machine is given', function () {
    $configs = json_decode(xstate(), true);

    expect(array_keys($configs))->toBe(['ImportedPostStatusMachine', 'PostStatusMachine', 'OrderStatusMachine', 'PaymentStatusMachine'])
        ->and($configs['PaymentStatusMachine'])->toBe(json_decode(xstate(['machine' => PaymentStatusMachine::class]), true));
});

it('exports every registered machine once, as one TypeScript module, when no machine is given', function () {
    expect(xstate([], 'xstate-ts'))
        ->toContain('export const importedPostStatusMachine = {')
        ->toContain('export const postStatusMachine = {')
        ->toContain('export const orderStatusMachine = {')
        ->toContain('export const paymentStatusMachine = {');
});

it('refuses to export every machine as XState when two machines share a class name', function (string $format) {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Orders', __DIR__ . '/Fixtures/LegacyOrders']]);

    $this->artisan('interlock:signalbox', ['--format' => $format])
        ->expectsOutputToContain('machine IDs are class names without their namespace, and ' . LegacyOrderStatusMachine::class . ' and ' . OrderStatusMachine::class . ' share one.')
        ->assertFailed();
})->with(['xstate', 'xstate-ts']);

it('exports one of two machines that share a class name', function () {
    config(['interlock.discovery.paths' => [__DIR__ . '/Fixtures/Orders', __DIR__ . '/Fixtures/LegacyOrders']]);

    expect(json_decode(xstate(['machine' => LegacyOrderStatusMachine::class]), true))->toHaveKey('id', 'OrderStatusMachine');
});

it('writes the export to a file instead of stdout when given an output path', function () {
    $path = sys_get_temp_dir() . '/interlock-signalbox-' . uniqid() . '.ts';

    try {
        $this->artisan('interlock:signalbox', ['machine' => PaymentStatusMachine::class, '--format' => 'xstate-ts', '--output' => $path])
            ->expectsOutputToContain("Exported to [{$path}].")
            ->doesntExpectOutputToContain('export const')
            ->assertSuccessful();

        expect(file_get_contents($path))->toBe(xstate(['machine' => PaymentStatusMachine::class], 'xstate-ts'));
    } finally {
        @unlink($path);
    }
});

dataset('fixture machines', [
    'post' => [PostStatusMachine::class, [__DIR__ . '/Fixtures/BlogPosts']],
    'imported post' => [ImportedPostStatusMachine::class, [__DIR__ . '/Fixtures/BlogPosts']],
    'order' => [OrderStatusMachine::class, [__DIR__ . '/Fixtures/Orders']],
    'payment' => [PaymentStatusMachine::class, [__DIR__ . '/Fixtures/Orders']],
    'contract legal approval' => [LegalApprovalMachine::class, [__DIR__ . '/Fixtures/Contracts']],
    'contract finance approval' => [FinanceApprovalMachine::class, [__DIR__ . '/Fixtures/Contracts']],
    'expense' => [ExpenseStatusMachine::class, [__DIR__ . '/Fixtures/Expenses']],
    'claim' => [ClaimStatusMachine::class, [__DIR__ . '/Fixtures/Exports']],
]);

it('exports the fixture machines as XState JSON', function (string $machine, array $paths) {
    config(['interlock.discovery.paths' => $paths]);

    expect(xstate(['machine' => $machine]))->toMatchSnapshot();
})->with('fixture machines');

it('exports the fixture machines as XState TypeScript', function (string $machine, array $paths) {
    config(['interlock.discovery.paths' => $paths]);

    expect(xstate(['machine' => $machine], 'xstate-ts'))->toMatchSnapshot();
})->with('fixture machines');
