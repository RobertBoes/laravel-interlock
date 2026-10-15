<?php

namespace RobertBoes\Interlock\Export;

enum ExportFormat: string
{
    case Mermaid = 'mermaid';
    case XState = 'xstate';
    case XStateTypeScript = 'xstate-ts';
}
