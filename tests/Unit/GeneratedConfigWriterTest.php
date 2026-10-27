<?php

declare(strict_types=1);

namespace FluidStan\Tests\Unit;

use FluidStan\Configuration\ConfigurationLoader;
use FluidStan\Runner\GeneratedConfigWriter;
use Nette\Neon\Neon;
use PHPUnit\Framework\TestCase;

final class GeneratedConfigWriterTest extends TestCase
{
    public function testWritesPhpStanConfiguration(): void
    {
        $dir = sys_get_temp_dir() . '/fluidstan-config-' . getmypid();
        $configuration = (new ConfigurationLoader())->fromParameters([
            'bootstrapFiles' => ['/project/boot.php'],
            'phpstan' => ['includes' => ['/project/extra.neon'], 'parameters' => ['paths' => ['ignored'], 'checkFoo' => true]],
        ], '/project');

        (new GeneratedConfigWriter())->write($configuration, '/gen', $dir . '/phpstan.neon', '/project/vendor/autoload.php', ['/project/env.php']);

        $written = Neon::decode((string) file_get_contents($dir . '/phpstan.neon'));
        $this->assertIsArray($written);
        $this->assertSame([GeneratedConfigWriter::extensionFile(), '/project/extra.neon'], $written['includes'] ?? null);
        $parameters = $written['parameters'] ?? null;
        $this->assertIsArray($parameters);
        $this->assertSame(8, $parameters['level'] ?? null);
        $this->assertSame(['/gen'], $parameters['paths'] ?? null, 'generated dir wins over user parameters');
        $this->assertSame(['/project/vendor/autoload.php', '/project/boot.php', '/project/env.php'], $parameters['bootstrapFiles'] ?? null);
        $this->assertSame('/project/.cache/fluidstan/phpstan', $parameters['tmpDir'] ?? null);
        $this->assertTrue($parameters['checkFoo'] ?? null);
        $this->assertFalse($parameters['reportUnmatchedIgnoredErrors'] ?? null);
        $this->assertTrue(is_file(GeneratedConfigWriter::extensionFile()));

        unlink($dir . '/phpstan.neon');
        rmdir($dir);
    }
}
