<?php

namespace RobertBoes\Interlock\Tests\Fixtures\UnsoundMachines;

enum TaskStatus: string
{
    case Todo = 'todo';
    case Doing = 'doing';
    case Done = 'done';
    case Dropped = 'dropped';
}
