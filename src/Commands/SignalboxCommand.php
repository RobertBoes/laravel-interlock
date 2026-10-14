<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use RobertBoes\Interlock\Export\ExportFormat;
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
    protected $signature = 'interlock:signalbox
        {machine? : The state machine class to export; every registered machine when none is given}
        {--format=mermaid : The format to export in: mermaid, xstate (JSON) or xstate-ts (TypeScript)}
        {--output= : The file to write the export to, instead of stdout}';

    protected $description = 'Export the state machines as Mermaid diagrams or XState machine configs, one machine or every registered machine';

    public function handle(ModelRegistry $registry, GraphRepository $graphs): int
    {
        /** @var string $formatOption */
        $formatOption = $this->option('format');
        /** @var string|null $machineClass */
        $machineClass = $this->argument('machine');
        /** @var string|null $path */
        $path = $this->option('output');

        $format = ExportFormat::tryFrom($formatOption);

        if ($format === null) {
            $this->components->error(sprintf(
                'Interlock cannot export the format [%s]. The formats are: %s.',
                $formatOption,
                implode(', ', array_map(fn(ExportFormat $format) => $format->value, ExportFormat::cases())),
            ));

            return self::FAILURE;
        }

        $machineClass = $machineClass === null ? null : ltrim($machineClass, '\\');
        $machines = $this->machines($registry, $graphs, $machineClass);

        if ($machineClass !== null && $machines === []) {
            $this->components->error(sprintf(
                'No registered model uses the state machine [%s]. Discovery scans the paths in interlock.discovery.paths for models with #[UseStateMachine]; register models outside them in interlock.models.',
                $machineClass,
            ));

            return self::FAILURE;
        }

        $sharedIds = $machineClass === null && $format !== ExportFormat::Mermaid ? $this->machinesSharingAnId($machines) : new Collection();

        if ($sharedIds->isNotEmpty()) {
            $this->components->error(sprintf(
                'Interlock cannot export every machine as XState: machine IDs are class names without their namespace, and %s share one. Rename one of the machines, or export them one at a time.',
                $sharedIds->map(fn(Collection $machineClasses) => $machineClasses->implode(' and '))->implode('; '),
            ));

            return self::FAILURE;
        }

        $export = $machineClass === null ? $this->exportAll($format, $machines) : $this->export($format, ...reset($machines));

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
            foreach ($declarations as $stateAttribute => $machineClass) {
                if (isset($machines[$machineClass]) || ($only !== null && $machineClass !== $only)) {
                    continue;
                }

                $graph = $graphs->for(new $model());
                $compiled = $graph->machines[$stateAttribute];
                $machines[$machineClass] = [$compiled, $graph->transitionsOf($compiled)];
            }
        }

        ksort($machines);

        return $machines;
    }

    /**
     * @param  list<CompiledTransition>  $transitions
     */
    private function export(ExportFormat $format, CompiledMachine $machine, array $transitions): string
    {
        return match ($format) {
            ExportFormat::XState => XStateConfig::json(XStateConfig::of($machine, $transitions)),
            ExportFormat::XStateTypeScript => XStateTypeScript::of($machine, $transitions),
            ExportFormat::Mermaid => MermaidDiagram::of($machine, $transitions),
        };
    }

    /**
     * Exports every machine in one document: Mermaid diagrams as Markdown, XState configs as one JSON object keyed by
     * machine ID, and TypeScript as one module.
     *
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     */
    private function exportAll(ExportFormat $format, array $machines): string
    {
        return match ($format) {
            ExportFormat::XState => XStateConfig::json((object) $this->xstateConfigs($machines)),
            ExportFormat::XStateTypeScript => implode(PHP_EOL, array_map(fn(array $machine) => XStateTypeScript::of(...$machine), $machines)),
            ExportFormat::Mermaid => $this->markdown($machines),
        };
    }

    /**
     * The machines whose XState IDs are the same, so that one would overwrite the other in an export of every machine.
     *
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     * @return Collection<int, Collection<int, class-string<StateMachine>>>
     */
    private function machinesSharingAnId(array $machines): Collection
    {
        return new Collection($machines)
            ->mapToGroups(fn(array $machine, string $machineClass) => [XStateConfig::id($machine[0]) => $machineClass])
            ->filter(fn(Collection $machineClasses) => $machineClasses->count() > 1)
            ->values();
    }

    /**
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     * @return array<string, array<string, mixed>>
     */
    private function xstateConfigs(array $machines): array
    {
        return new Collection($machines)
            ->mapWithKeys(fn(array $machine) => [XStateConfig::id($machine[0]) => XStateConfig::of(...$machine)])
            ->all();
    }

    /**
     * Puts the diagrams in one Markdown document, each under a heading with its machine's name, ready to paste into docs or a pull request.
     *
     * @param  array<class-string<StateMachine>, array{CompiledMachine, list<CompiledTransition>}>  $machines
     */
    private function markdown(array $machines): string
    {
        return new Collection($machines)
            ->map(fn(array $machine, string $machineClass) => $this->markdownSection($machineClass, ...$machine))
            ->implode(PHP_EOL);
    }

    /**
     * @param  class-string<StateMachine>  $machineClass
     * @param  list<CompiledTransition>  $transitions
     */
    private function markdownSection(string $machineClass, CompiledMachine $machine, array $transitions): string
    {
        return '## ' . class_basename($machineClass) . PHP_EOL . PHP_EOL . '```mermaid' . PHP_EOL . MermaidDiagram::of($machine, $transitions) . '```' . PHP_EOL;
    }
}
