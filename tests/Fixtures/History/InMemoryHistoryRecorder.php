<?php

namespace RobertBoes\Interlock\Tests\Fixtures\History;

use RobertBoes\Interlock\History\HistoryRecord;
use RobertBoes\Interlock\History\HistoryRecorder;

final class InMemoryHistoryRecorder implements HistoryRecorder
{
    /** @var list<HistoryRecord> */
    public array $records = [];

    public function record(HistoryRecord $record): void
    {
        $this->records[] = $record;
    }
}
