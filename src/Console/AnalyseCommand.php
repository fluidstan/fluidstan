<?php

declare(strict_types=1);

namespace FluidStan\Console;

use FluidStan\Analyser;
use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Diagnostics\BaselineWriter;
use FluidStan\Diagnostics\Diagnostic;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Helper\TableSeparator;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class AnalyseCommand extends Command
{
    public function __construct(private readonly ?string $autoloadFile)
    {
        parent::__construct('analyse');
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Analyses Fluid templates and components')
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Template files or directories (default: paths from the configuration)')
            ->addOption('configuration', 'c', InputOption::VALUE_REQUIRED, 'Path to fluidstan.neon')
            ->addOption('error-format', null, InputOption::VALUE_REQUIRED, 'table or json', 'table')
            ->addOption('no-phpstan', null, InputOption::VALUE_NONE, 'Only parse and transpile; skip the PHPStan run')
            ->addOption('generate-baseline', 'b', InputOption::VALUE_OPTIONAL, 'Write the current errors as ignoreErrors to a baseline file (default: fluidstan-baseline.neon next to the configuration)', false)
            ->addOption('debug', null, InputOption::VALUE_NONE, 'Keep and show the path of the generated code');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $configFile = $input->getOption('configuration');
        $cwd = (string) getcwd();
        if ($configFile === null && is_file($cwd . '/' . ConfigurationLoader::DEFAULT_FILE_NAME)) {
            $configFile = $cwd . '/' . ConfigurationLoader::DEFAULT_FILE_NAME;
        }

        $baselineFile = $this->baselineFile($input->getOption('generate-baseline'), \is_string($configFile) ? \dirname((string) realpath($configFile)) : $cwd);
        if ($baselineFile !== null && !str_ends_with($baselineFile, '.neon')) {
            $io->error('The baseline file must have the extension .neon.');
            return self::INVALID;
        }

        $loader = new ConfigurationLoader();
        // PHPStan's loader leaves the baseline being generated out, so it does not hide what it is about to record
        $configuration = $loader->load(\is_string($configFile) ? $configFile : null, $cwd, $baselineFile);
        if ($baselineFile !== null) {
            $configuration = $configuration->withoutUnmatchedIgnoredErrorsReport();
        }

        $paths = $input->getArgument('paths');
        $paths = \is_array($paths) ? array_values(array_filter($paths, is_string(...))) : [];
        if ($paths !== []) {
            $configuration = $configuration->withPaths($loader->resolvePaths($paths, $cwd));
        }

        if ($configuration->paths === []) {
            $io->error('No paths given. Pass template paths as arguments or configure "paths" in fluidstan.neon.');
            return self::INVALID;
        }


        $analyser = new Analyser(
            $configuration,
            $this->autoloadFile,
            $this->locatePhpStan(),
            !(bool) $input->getOption('no-phpstan'),
        );
        $verbose = $output->isVerbose();
        $result = $analyser->analyse(static function (string $file) use ($io, $verbose): void {
            if ($verbose) {
                $io->writeln('Parsing ' . $file);
            }
        });

        if ($baselineFile !== null) {
            $count = (new BaselineWriter())->write($result->diagnostics, $baselineFile);
            $io->success($count === 0
                ? \sprintf('No errors were found during the analysis. An empty baseline was written to %s.', $this->relativePath($baselineFile, $cwd))
                : \sprintf('Baseline with %d error%s written to %s.', $count, $count === 1 ? '' : 's', $this->relativePath($baselineFile, $cwd)));
            return self::SUCCESS;
        }

        $format = $input->getOption('error-format');
        if ($format === 'json') {
            $output->writeln((string) json_encode([
                'totals' => ['errors' => \count($result->diagnostics), 'templates' => $result->templateCount],
                'errors' => array_map(static fn(Diagnostic $d): array => [
                    'file' => $d->file,
                    'line' => $d->line,
                    'message' => $d->message,
                    'identifier' => $d->identifier,
                ], $result->diagnostics->all()),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return \count($result->diagnostics) === 0 ? self::SUCCESS : self::FAILURE;
        }

        if ($configuration->strict === null) {
            $io->note('Strict mode is off. Set "strict: true" in fluidstan.neon to also report what Fluid tolerates silently: cast argument values, paths through null, conditions that are always true or false, loose comparisons and rendered booleans. Set "strict: false" to keep it off and hide this note.');
        }

        foreach ($result->diagnostics->byFile() as $file => $diagnostics) {
            $table = new Table($output);
            $table->setHeaders(['Line', OutputFormatter::escape($this->relativePath($file, $cwd))]);
            $rows = [];
            foreach ($diagnostics as $index => $diagnostic) {
                if ($index > 0) {
                    $rows[] = new TableSeparator();
                }

                $message = OutputFormatter::escape($diagnostic->message);
                if ($diagnostic->identifier !== null) {
                    $message .= "\n<fg=gray>🪪  " . $diagnostic->identifier . '</>';
                }

                $rows[] = [$diagnostic->line === null ? '' : (string) $diagnostic->line, wordwrap($message, 110)];
            }

            $table->setRows($rows);
            $table->render();
            $output->writeln('');
        }

        if ((bool) $input->getOption('debug')) {
            $io->note('Generated code: ' . $result->generatedDir);
        }

        $count = \count($result->diagnostics);
        if ($count === 0) {
            $io->success(\sprintf('No errors in %d template%s', $result->templateCount, $result->templateCount === 1 ? '' : 's'));
            return self::SUCCESS;
        }

        $affected = \count($result->diagnostics->byFile());
        $io->error(\sprintf(
            'Found %d error%s in %d of %d template%s',
            $count,
            $count === 1 ? '' : 's',
            $affected,
            $result->templateCount,
            $result->templateCount === 1 ? '' : 's',
        ));
        return self::FAILURE;
    }

    /**
     * The absolute path of the baseline to write, or null when none is requested: the option is
     * absent (false), given without a value (null) or given with a path.
     */
    private function baselineFile(mixed $option, string $configDirectory): ?string
    {
        if ($option === false) {
            return null;
        }

        $path = (new ConfigurationLoader())->resolvePath(\is_string($option) && $option !== '' ? $option : 'fluidstan-baseline.neon', $configDirectory);

        // The file may not exist yet; the directory must, for PHPStan's loader to recognise the include
        $directory = realpath(\dirname($path));
        return ($directory === false ? \dirname($path) : $directory) . '/' . basename($path);
    }

    private function locatePhpStan(): ?string
    {
        $candidates = [];
        if ($this->autoloadFile !== null) {
            $candidates[] = \dirname($this->autoloadFile) . '/bin/phpstan';
        }

        $candidates[] = \dirname(__DIR__, 2) . '/vendor/bin/phpstan';
        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function relativePath(string $file, string $cwd): string
    {
        $prefix = rtrim($cwd, '/') . '/';
        return str_starts_with($file, $prefix) ? substr($file, \strlen($prefix)) : $file;
    }
}
