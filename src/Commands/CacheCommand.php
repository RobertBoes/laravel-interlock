<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Console\Command;
use RobertBoes\Interlock\Discovery\ModelRegistry;

final class CacheCommand extends Command
{
    protected $signature = 'interlock:cache';

    protected $description = 'Discover the interlocked models and cache them, so requests do not scan for them';

    public function handle(ModelRegistry $registry): void
    {
        $registry->cache();

        $this->components->info('Interlocked models cached successfully.');
    }
}
