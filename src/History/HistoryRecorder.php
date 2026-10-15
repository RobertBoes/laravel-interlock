<?php

namespace RobertBoes\Interlock\History;

/**
 * Called inside the transaction of the write it records, so throwing rolls that write back.
 */
interface HistoryRecorder
{
    public function record(HistoryRecord $record): void;
}
