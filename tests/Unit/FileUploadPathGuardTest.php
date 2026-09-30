<?php

namespace Tests\Unit;

use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Filament leaves file-path tampering prevention off by default. Without it, a stored path placed in an upload field's form
 * state is described with its name, size, type and a URL, so every upload field in the admin must opt in.
 */
class FileUploadPathGuardTest extends TestCase
{
    public function test_every_admin_file_upload_refuses_stored_paths(): void
    {
        $root = dirname(__DIR__, 2).'/app/Filament';
        $found = 0;
        $unguarded = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (self::uploadChains((string) file_get_contents($file->getPathname())) as [$line, $methods]) {
                $found++;
                if (! in_array('preventFilePathTampering', $methods, true)) {
                    $unguarded[] = substr($file->getPathname(), strlen($root) + 1).':'.$line;
                }
            }
        }

        $this->assertGreaterThan(0, $found, 'The scan found no upload fields, so it no longer reads the admin correctly.');
        $this->assertSame([], $unguarded, 'These upload fields do not call preventFilePathTampering().');
    }

    public function test_the_scan_reads_each_fields_own_method_chain(): void
    {
        $source = <<<'PHP'
            <?php
            $schema = [
                FileUpload::make('a')->disk('local')->preventFilePathTampering()->helperText(fn () => $x->preventFilePathTampering()),
                \Filament\Forms\Components\FileUpload::make('b')->helperText(fn () => $x->preventFilePathTampering()),
                TextInput::make('c')->preventFilePathTampering(),
            ];
            PHP;

        $this->assertSame([
            [3, ['disk', 'preventFilePathTampering', 'helperText']],
            [4, ['helperText']],
        ], self::uploadChains($source));
    }

    /** @return list<array{int, list<string>}> Each FileUpload::make() call's line and the methods chained on it, in order. */
    private static function uploadChains(string $source): array
    {
        $tokens = array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->isIgnorable()));
        $chains = [];
        foreach ($tokens as $index => $token) {
            if (! $token->is([T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED]) || ! str_ends_with($token->text, 'FileUpload')
                || ($tokens[$index + 1]->text ?? '') !== '::' || ($tokens[$index + 2]->text ?? '') !== 'make') {
                continue;
            }
            // Only calls at the chain's own level count: one inside an argument, such as a closure, belongs to something else.
            $methods = [];
            $depth = 0;
            for ($next = $index + 3; $next < count($tokens); $next++) {
                $text = $tokens[$next]->text;
                if (in_array($text, ['(', '[', '{'], true)) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if (--$depth < 0) {
                        break;
                    }
                } elseif ($depth === 0 && in_array($text, [',', ';'], true)) {
                    break;
                } elseif ($depth === 0 && in_array($text, ['->', '?->'], true) && isset($tokens[$next + 1])) {
                    $methods[] = $tokens[$next + 1]->text;
                }
            }
            $chains[] = [$token->line, $methods];
        }

        return $chains;
    }
}
