<?php

declare(strict_types=1);

namespace FluidStan\Runner;

use Symfony\Component\Process\Process;

/**
 * Runs PHPStan as a subprocess. PHPStan is not usable as a library, and running it in a
 * separate process also keeps its memory and autoloading separate from the Fluid parsing.
 */
final readonly class PhpStanRunner
{
    public function __construct(private string $phpstanBinary) {}

    public function run(string $configFile, ?string $memoryLimit = null): PhpStanResult
    {
        if (!is_file($this->phpstanBinary)) {
            throw new \RuntimeException(\sprintf('PHPStan binary "%s" not found.', $this->phpstanBinary));
        }

        $command = [
            PHP_BINARY,
            $this->phpstanBinary,
            'analyse',
            '--configuration=' . $configFile,
            '--error-format=json',
            '--no-progress',
            '--no-interaction',
            '--no-ansi',
            '--memory-limit=' . ($memoryLimit ?? '1G'),
        ];
        $process = new Process($command, null, ['XDEBUG_MODE' => 'off']);
        $process->setTimeout(null);
        $process->run();

        $output = $process->getOutput();
        $decoded = json_decode($output, true);
        if (!\is_array($decoded)) {
            throw new \RuntimeException(\sprintf(
                "PHPStan failed (exit code %d):\n%s\n%s",
                $process->getExitCode() ?? -1,
                trim($output),
                trim($process->getErrorOutput()),
            ));
        }

        $errors = [];
        $files = \is_array($decoded['files'] ?? null) ? $decoded['files'] : [];
        foreach ($files as $file => $fileResult) {
            $messages = \is_array($fileResult) && \is_array($fileResult['messages'] ?? null) ? $fileResult['messages'] : [];
            foreach ($messages as $message) {
                if (!\is_array($message)) {
                    continue;
                }

                $errors[] = new PhpStanError(
                    (string) $file,
                    \is_int($message['line'] ?? null) ? $message['line'] : null,
                    \is_string($message['message'] ?? null) ? $message['message'] : '',
                    \is_string($message['identifier'] ?? null) ? $message['identifier'] : null,
                );
            }
        }

        $general = [];
        foreach (\is_array($decoded['errors'] ?? null) ? $decoded['errors'] : [] as $message) {
            $general[] = \is_string($message) ? $message : json_encode($message, JSON_THROW_ON_ERROR);
        }

        return new PhpStanResult($errors, $general, $output);
    }
}
