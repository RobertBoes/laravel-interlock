<?php

namespace RobertBoes\Interlock\History;

/**
 * Writes history for the machines that record it. Bind your own implementation in the container to write it elsewhere.
 * It is called inside the transaction of the write it records, so throwing rolls that write back.
 */
interface HistoryRecorder
{
    public function record(HistoryRecord $record): void;
}
