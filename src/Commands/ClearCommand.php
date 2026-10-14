<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Console\Command;
use RobertBoes\Interlock\Discovery\ModelRegistry;

final class ClearCommand extends Command
{
    protected $signature = 'interlock:clear';

    protected $description = 'Remove the cached interlocked models';

    public function handle(ModelRegistry $registry): void
    {
        $registry->clearCache();

        $this->components->info('Cached interlocked models cleared successfully.');
    }
}
