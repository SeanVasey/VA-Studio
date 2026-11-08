<?php

namespace App\Domain\Contracts\Production;

/**
 * The implementation pin of `vasey-license-pdf-v2`, derived exactly as {@see VaseyLicenseImplementation} derives v1's
 * (that class is itself pinned by v1's manifest, so it is not parameterized here): every application class reachable
 * from the roots through `use App\…` imports and references to classes of the same namespace.
 *
 * The roots are the profile definition and the checkout printable set bound to its faces. The v2 renderer does not
 * exist yet; when it does, its entry points join the roots and the manifest is rebuilt before any issuance.
 * {@see VaseyLicenseV2Profile} is excluded: it holds the manifest's own SHA-256 and anchors the pin.
 */
final class VaseyLicenseV2Implementation
{
    public const ROOTS = [
        'app/Domain/Contracts/Production/VaseyLicenseV2ProfileDefinition.php',
        'app/Domain/Contracts/Production/VaseyLicenseV2Implementation.php',
        'app/Domain/Commerce/ContractIdentity/ContractPrintableSet.php',
    ];

    public const ANCHOR = 'app/Domain/Contracts/Production/VaseyLicenseV2Profile.php';

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
