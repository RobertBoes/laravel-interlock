<?php

namespace RobertBoes\Interlock\Tests\Fixtures\Deletions;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use RobertBoes\Interlock\System\RunsAsSystem;

final class ProcessDeletion implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public function __construct(public readonly int $deletionRequestId) {}

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [new RunsAsSystem('Deletion job')];
    }

    public function handle(): void
    {
        DeletionRequest::findOrFail($this->deletionRequestId)->transition('complete');
    }
}
