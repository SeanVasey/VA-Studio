<?php

namespace App\Support;

use Closure;
use Symfony\Component\Process\Process;

/**
 * Process factory for the pinned grant renderers (`FreeGrantRendererProcess`, `ProductionFreeGrantRendererProcess`,
 * `PaidGrantRendererProcess`) when this process is not the CLI.
 *
 * Those classes are frozen by their contract profiles (`resources/contracts/*-v1/profile-assets.json`) and build
 * `[PHP_BINARY, '-n', ...]`. Under PHP-FPM `PHP_BINARY` is the FPM daemon, which prints its usage text instead of
 * running the renderer script. Each class already accepts an optional process factory; the container passes this one
 * outside the CLI. It replaces only the executable with the validated CLI binary (`PhpCliBinary`) and re-derives the
 * renderer's fixed sibling library directory from that binary instead of the FPM one. Every flag, the script, the
 * working directory, the scrubbed environment, the input and the 60 s timeout are passed through unchanged, and the
 * renderer still verifies its pinned profile and bounds its own output.
 */
final class PhpCliProcess
{
    /** The pinned renderers' architecture list for `dirname(PHP_BINARY, 2).'/lib/<arch>'`. */
    private const LIBRARY_ARCHITECTURES = ['x86_64-linux-gnu', 'aarch64-linux-gnu'];

    /**
     * @return Closure(array<int, string>, string, array<string, string|false>, string): Process
     */
    public static function factory(PhpCliBinary $binary, string $runningBinary = PHP_BINARY): Closure
    {
        return function (array $command, string $cwd, array $environment, string $input) use ($binary, $runningBinary): Process {
            // Only a command built by a pinned renderer around this process's own binary is rewritten.
            if (($command[0] ?? null) !== $runningBinary || ($command[1] ?? null) !== '-n') {
                throw new PhpCliBinaryUnavailable('unexpected_command');
            }
            $cli = $binary->path();
            $command[0] = $cli;
            // Exactly what the renderer would have set had `PHP_BINARY` been this binary.
            $libraries = self::libraries($cli);
            $environment['LD_LIBRARY_PATH'] = $libraries === [] ? false : implode(PATH_SEPARATOR, $libraries);

            return new Process($command, $cwd, $environment, $input, 60);
        };
    }

    /**
     * The pinned renderers' sibling library rule, applied to the binary that actually runs.
     *
     * @return list<string>
     */
    public static function libraries(string $binary): array
    {
        $libraries = [];
        foreach (self::LIBRARY_ARCHITECTURES as $architecture) {
            $directory = realpath(dirname($binary, 2).'/lib/'.$architecture);
            if ($directory !== false) {
                $libraries[] = $directory;
            }
        }

        return $libraries;
    }
}
