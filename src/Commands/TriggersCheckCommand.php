<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use RobertBoes\Interlock\Triggers\TriggerMonitor;
use RobertBoes\Interlock\Triggers\TriggerStatus;

final class TriggersCheckCommand extends Command
{
    protected $signature = 'interlock:triggers:check';

    protected $description = 'Fail when a trigger is overdue: its models match, but no run has finished lately or they have matched for too long';

    public function handle(TriggerMonitor $monitor): int
    {
        $overdue = array_filter($monitor->statuses(), fn(TriggerStatus $status) => $status->overdue);

        if ($overdue === []) {
            $this->components->info('No trigger is overdue.');

            return self::SUCCESS;
        }

        foreach ($overdue as $status) {
            $this->components->error($this->describe($status));
        }

        $this->components->warn('Check that the scheduler and a queue worker run, and that the guards and the transition let the matching models leave its from-states.');

        return self::FAILURE;
    }

    private function describe(TriggerStatus $status): string
    {
        $facts = [
            sprintf('%d %s %s it', $status->matching, Str::plural('model', $status->matching), $status->matching === 1 ? 'matches' : 'match'),
            $status->lastRunAt === null ? 'no run has finished' : 'the last run finished at ' . $status->lastRunAt->toDateTimeString(),
        ];

        if ($status->matchingSince !== null) {
            $facts[] = 'models have matched it since ' . $status->matchingSince->toDateTimeString();
        }

        return sprintf(
            'Trigger [%s] of [%s] is overdue: %s, and %s.',
            $status->transition,
            $status->model,
            implode(', ', array_slice($facts, 0, -1)),
            $facts[array_key_last($facts)],
        );
    }
}
