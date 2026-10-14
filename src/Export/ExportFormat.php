<?php

namespace RobertBoes\Interlock\Export;

/**
 * The formats interlock:signalbox exports a state machine in.
 */
enum ExportFormat: string
{
    case Mermaid = 'mermaid';
    case XState = 'xstate';
    case XStateTypeScript = 'xstate-ts';
}
