<?php

namespace App\Domain\Contracts\Production;

/**
 * The implementation pin of `vasey-license-pdf-v1`, derived mechanically: every application class reachable from the
 * renderer, the binder, the template census and the profile definition, through `use App\…` imports and references to
 * classes of the same namespace. The build script pins exactly this list, and a test fails when the manifest's pin
 * and the derived closure differ, so a class that shapes the printed contract cannot be left unpinned.
 *
 * {@see VaseyLicenseProfile} is excluded: it holds the manifest's own SHA-256 and anchors the pin. Vendor code is
 * pinned by package identity instead.
 */
final class VaseyLicenseImplementation
{
    public const ROOTS = [
        'app/Domain/Contracts/Production/VaseyLicensePdfRenderer.php',
        'app/Domain/Contracts/Production/ContractVariableBinder.php',
        'app/Domain/Contracts/Production/ContractTemplateCensus.php',
        'app/Domain/Contracts/Production/VaseyLicenseProfileDefinition.php',
        'app/Domain/Contracts/Production/VaseyLicenseImplementation.php',
    ];

    public const ANCHOR = 'app/Domain/Contracts/Production/VaseyLicenseProfile.php';

    /** @return list<string> project-relative paths, sorted */
    public static function closure(string $projectRoot): array
    {
        $queue = self::ROOTS;
        $seen = [];
        while ($queue !== []) {
            $path = array_shift($queue);
            if (isset($seen[$path]) || $path === self::ANCHOR) {
                continue;
            }
            $seen[$path] = true;
            foreach (self::dependencies($projectRoot, $path) as $dependency) {
                $queue[] = $dependency;
            }
        }
        $paths = array_keys($seen);
        sort($paths, SORT_STRING);

        return $paths;
    }

    /** @return list<string> */
    private static function dependencies(string $projectRoot, string $path): array
    {
        $source = file_get_contents($projectRoot.'/'.$path);
        if (! is_string($source)) {
            throw new \RuntimeException('Unreadable implementation file: '.$path);
        }
        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $code .= is_array($token) ? $token[1] : $token;
        }
        $found = [];
        preg_match_all('/^use (App\\\\[A-Za-z0-9_\\\\]+);/m', $code, $imports);
        preg_match_all('/\\\\?(App\\\\[A-Za-z0-9_\\\\]+)/', $code, $qualified);
        foreach ([...$imports[1], ...$qualified[1]] as $class) {
            $file = 'app/'.str_replace('\\', '/', substr($class, 4)).'.php';
            if (is_file($projectRoot.'/'.$file)) {
                $found[] = $file;
            }
        }
        $directory = dirname($path);
        foreach (glob($projectRoot.'/'.$directory.'/*.php') ?: [] as $sibling) {
            $name = basename($sibling, '.php');
            $relative = $directory.'/'.$name.'.php';
            if ($relative !== $path && preg_match('/(?<![\w\\\\$])'.$name.'\b/', $code) === 1) {
                $found[] = $relative;
            }
        }

        return array_values(array_unique($found));
    }
}
