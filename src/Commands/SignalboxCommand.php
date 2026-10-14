<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Console\Command;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Export\MermaidDiagram;
use RobertBoes\Interlock\Export\XStateConfig;
use RobertBoes\Interlock\Export\XStateTypeScript;
use RobertBoes\Interlock\Graph\CompiledMachine;
use RobertBoes\Interlock\Graph\CompiledTransition;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\StateMachine;

final class SignalboxCommand extends Command
{
    private const array FORMATS = ['mermaid', 'xstate', 'xstate-ts'];

    protected $signature = 'interlock:signalbox
        {machine? : The state machine class to export; every registered machine when none is given}
        {--format=mermaid : The format to export in: mermaid, xstate (JSON) or xstate-ts (TypeScript)}
        {--output= : The file to write the export to, instead of stdout}';

    protected $description = 'Export the state machines as Mermaid diagrams or XState machine configs, one machine or every registered machine';

    public function handle(ModelRegistry $registry, GraphRepository $graphs): int
    {
        /** @var string $format */
        $format = $this->option('format');
        /** @var string|null $machine */
        $machine = $this->argument('machine');
        /** @var string|null $path */
        $path = $this->option('output');

        if (! in_array($format, self::FORMATS, true)) {
            $this->components->error(sprintf(
                'Interlock cannot export the format [%s]. The formats are: %s.',
                $format,
                implode(', ', self::FORMATS),
            ));

            return self::FAILURE;
        }

        $machine = $machine === null ? null : ltrim($machine, '\\');
        $machines = $this->machines($registry, $graphs, $machine);

        if ($machine !== null && $machines === []) {
            $this->components->error(sprintf(
                'No registered model uses the state machine [%s]. Discovery scans the paths in interlock.discovery.paths for models with #[UseStateMachine]; register models outside them in interlock.models.',
                $machine,
            ));

            return self::FAILURE;
        }

        $sharedIds = $machine === null && $format !== 'mermaid' ? $this->machinesSharingAnId($machines) : [];

        if ($sharedIds !== []) {
            $this->components->error(sprintf(
                'Interlock cannot export every machine as XState: machine IDs are class names without their namespace, and %s share one. Rename one of the machines, or export them one at a time.',
                implode('; ', array_map(fn(array $classes) => implode(' and ', $classes), $sharedIds)),
            ));

            return self::FAILURE;
        }

        $export = $machine === null ? $this->exportAll($format, $machines) : $this->export($format, ...reset($machines));

        if ($path === null) {
            $this->output->write($export);

            return self::SUCCESS;
        }

        file_put_contents($path, $export);

        $this->components->info(sprintf('Exported to [%s].', $path));

        return self::SUCCESS;
    }

    /**
     * Each registered machine once, with its transitions, ordered by class name, or only the given machine.
     *
     * @return array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>
     */
    private function machines(ModelRegistry $registry, GraphRepository $graphs, ?string $only): array
    {
        $machines = [];

        foreach ($registry->models() as $model => $declarations) {
            foreach ($declarations as $attribute => $machine) {
                if (isset($machines[$machine]) || ($only !== null && $machine !== $only)) {
                    continue;
                }

                $graph = $graphs->for(new $model());
                $compiled = $graph->machines[$attribute];
                $machines[$machine] = [$compiled, $graph->transitionsOf($compiled)];
            }
        }

        ksort($machines);

        return $machines;
    }

    /**
     * @param  list<CompiledTransition>  $transitions
     */
    private function export(string $format, CompiledMachine $machine, array $transitions): string
    {
        return match ($format) {
            'xstate' => XStateConfig::json(XStateConfig::of($machine, $transitions)),
            'xstate-ts' => XStateTypeScript::of($machine, $transitions),
            default => MermaidDiagram::of($machine, $transitions),
        };
    }

    /**
     * Exports every machine in one document: Mermaid diagrams as Markdown, XState configs as one JSON object keyed by
     * machine ID, and TypeScript as one module.
     *
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     */
    private function exportAll(string $format, array $machines): string
    {
        return match ($format) {
            'xstate' => XStateConfig::json((object) $this->xstateConfigs($machines)),
            'xstate-ts' => implode(PHP_EOL, array_map(fn(array $machine) => XStateTypeScript::of(...$machine), $machines)),
            default => $this->markdown($machines),
        };
    }

    /**
     * The machines whose XState IDs are the same, so that one would overwrite the other in an export of every machine.
     *
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     * @return list<list<class-string<StateMachine>>>
     */
    private function machinesSharingAnId(array $machines): array
    {
        $byId = [];

        foreach ($machines as $machine => [$compiled]) {
            $byId[XStateConfig::id($compiled)][] = $machine;
        }

        return array_values(array_filter($byId, fn(array $classes) => count($classes) > 1));
    }

    /**
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     * @return array<string, array<string, mixed>>
     */
    private function xstateConfigs(array $machines): array
    {
        $configs = [];

        foreach ($machines as [$compiled, $transitions]) {
            $configs[XStateConfig::id($compiled)] = XStateConfig::of($compiled, $transitions);
        }

        return $configs;
    }

    /**
     * Puts the diagrams in one Markdown document, each under a heading with its machine's name, ready to paste into docs or a pull request.
     *
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     */
    private function markdown(array $machines): string
    {
        $sections = [];

        foreach ($machines as $machine => [$compiled, $transitions]) {
            $sections[] = '## ' . class_basename($machine) . PHP_EOL . PHP_EOL . '```mermaid' . PHP_EOL . MermaidDiagram::of($compiled, $transitions) . '```' . PHP_EOL;
        }

        return implode(PHP_EOL, $sections);
    }
}
