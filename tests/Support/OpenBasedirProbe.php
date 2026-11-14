<?php

namespace Tests\Support;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs a renderer's real child command with its script replaced by a probe, so a test sees the open_basedir the child
 * actually enforces. A sibling of the project root that shares its name as a prefix (`<root>-sibling-…/`) holds a
 * synthetic file the child must not read. (The workspace's own prefix sibling is not probed here: these children may
 * read the whole project root, and the test private disk lives inside it.) The workspace itself must still list.
 */
final class OpenBasedirProbe
{
    /** @return array{basedir: string, sibling_read: bool, workspace_list: bool, app_read: bool} */
    public static function run(array $command, string $cwd, array $environment, string $payload): array
    {
        $sibling = base_path().'-sibling-'.bin2hex(random_bytes(4));
        $secret = $sibling.'/secret.txt';
        $probe = $command;
        $probe[count($probe) - 1] = '-r';
        $probe[] = 'stream_get_contents(STDIN); echo json_encode(["basedir" => ini_get("open_basedir"),'
            .'"sibling_read" => @file_get_contents('.var_export($secret, true).') !== false,'
            .'"workspace_list" => @scandir(getenv("TMPDIR")) !== false,'
            .'"app_read" => @file_get_contents('.var_export(base_path('app/Support/CanonicalJson.php'), true).') !== false]);';
        $run = new Process($probe, $cwd, $environment, $payload, 60);
        $created = false;
        try {
            if (! mkdir($sibling, 0700)) {
                throw new RuntimeException('The open_basedir probe could not create its sibling directory.');
            }
            $created = true;
            if (file_put_contents($secret, 'synthetic') !== 9) {
                throw new RuntimeException('The open_basedir probe could not write its sibling file.');
            }
            $run->mustRun();
        } finally {
            // Remove only what this probe created, never a directory that was already there.
            if ($created) {
                if (is_file($secret) && ! is_link($secret)) {
                    unlink($secret);
                }
                rmdir($sibling);
            }
        }

        return json_decode($run->getOutput(), true, 4, JSON_THROW_ON_ERROR);
    }
}
