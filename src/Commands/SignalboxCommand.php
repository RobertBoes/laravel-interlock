<?php

namespace RobertBoes\Interlock\Commands;

use Illuminate\Console\Command;
use RobertBoes\Interlock\Discovery\ModelRegistry;
use RobertBoes\Interlock\Export\MermaidDiagram;
use RobertBoes\Interlock\Graph\GraphRepository;
use RobertBoes\Interlock\StateMachine;

final class SignalboxCommand extends Command
{
    private const array FORMATS = ['mermaid'];

    protected $signature = 'interlock:signalbox
        {machine? : The state machine class to export; every registered machine when none is given}
        {--format=mermaid : The format to export in: mermaid}
        {--output= : The file to write the export to, instead of stdout}';

    protected $description = 'Export the state machines as diagrams: one machine as a bare diagram, or every machine as Markdown';

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
        $diagrams = $this->diagrams($registry, $graphs, $machine);

        if ($machine !== null && $diagrams === []) {
            $this->components->error(sprintf(
                'No registered model uses the state machine [%s]. Discovery scans the paths in interlock.discovery.paths for models with #[UseStateMachine]; register models outside them in interlock.models.',
                $machine,
            ));

            return self::FAILURE;
        }

        $export = $machine === null ? $this->markdown($diagrams) : reset($diagrams);

        if ($path === null) {
            $this->output->write($export);

            return self::SUCCESS;
        }

        file_put_contents($path, $export);

        $this->components->info(sprintf('Exported to [%s].', $path));

        return self::SUCCESS;
    }

    /**
     * Draws each registered machine once, ordered by class name, or only the given machine.
     *
     * @return array<class-string<StateMachine>, string>
     */
    private function diagrams(ModelRegistry $registry, GraphRepository $graphs, ?string $only): array
    {
        $diagrams = [];

        foreach ($registry->models() as $model => $machines) {
            foreach ($machines as $attribute => $machine) {
                if (isset($diagrams[$machine]) || ($only !== null && $machine !== $only)) {
                    continue;
                }

                $graph = $graphs->for(new $model());
                $compiled = $graph->machines[$attribute];
                $diagrams[$machine] = MermaidDiagram::of($compiled, $graph->transitionsOf($compiled));
            }
        }

        ksort($diagrams);

        return $diagrams;
    }

    /**
     * Puts the diagrams in one Markdown document, each under a heading with its machine's name, ready to paste into docs or a pull request.
     *
     * @param  array<class-string<StateMachine>, string>  $diagrams
     */
    private function markdown(array $diagrams): string
    {
        $sections = [];

        foreach ($diagrams as $machine => $diagram) {
            $sections[] = '## ' . class_basename($machine) . PHP_EOL . PHP_EOL . '```mermaid' . PHP_EOL . $diagram . '```' . PHP_EOL;
        }

        return implode(PHP_EOL, $sections);
    }
}
