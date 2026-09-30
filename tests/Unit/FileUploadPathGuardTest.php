<?php

namespace Tests\Unit;

use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Filament leaves file-path tampering prevention off by default. Without it, a stored path placed in an upload field's form
 * state is described with its name, size, type and a URL, so every upload field in the admin must opt in, and none may opt
 * back out.
 */
class FileUploadPathGuardTest extends TestCase
{
    public function test_every_admin_file_upload_refuses_stored_paths(): void
    {
        $found = 0;
        $unguarded = [];
        foreach (self::adminSources() as $name => $source) {
            foreach (self::uploadChains($source) as [$line, $methods]) {
                $found++;
                if (! in_array('preventFilePathTampering()', $methods, true)) {
                    $unguarded[] = $name.':'.$line;
                }
            }
        }

        $this->assertGreaterThan(0, $found, 'The scan found no upload fields, so it no longer reads the admin correctly.');
        $this->assertSame([], $unguarded, 'These upload fields do not call preventFilePathTampering() without arguments.');
    }

    public function test_no_admin_code_turns_the_guard_off_or_widens_it(): void
    {
        $optOuts = [];
        foreach (self::adminSources() as $name => $source) {
            foreach (self::guardCallsWithArguments($source) as $line) {
                $optOuts[] = $name.':'.$line;
            }
        }

        $this->assertSame([], $optOuts, 'preventFilePathTampering() takes no arguments here: a condition or an allow callback can let stored paths through.');
    }

    public function test_the_scan_reads_each_fields_own_method_chain(): void
    {
        $source = <<<'PHP'
            <?php
            $schema = [
                FileUpload::make('a')->disk('local')->preventFilePathTampering()->helperText(fn () => $x->preventFilePathTampering()),
                \Filament\Forms\Components\FileUpload::make('b')->helperText(fn () => $x->preventFilePathTampering()),
                TextInput::make('c')->preventFilePathTampering(),
                FileUpload::make('d')->preventFilePathTampering(false),
                FileUpload::make('e')->preventFilePathTampering(allowFilePathUsing: fn () => true)->label(#[Pure] fn () => 'E'),
            ];
            $field->preventFilePathTampering(condition: fn () => false);
            PHP;

        $this->assertSame([
            [3, ['disk(...)', 'preventFilePathTampering()', 'helperText(...)']],
            [4, ['helperText(...)']],
            [6, ['preventFilePathTampering(...)']],
            [7, ['preventFilePathTampering(...)', 'label(...)']],
        ], self::uploadChains($source));
        $this->assertSame([6, 7, 9], self::guardCallsWithArguments($source));
    }

    /** @return array<string, string> Every PHP file under app/Filament, keyed by its path below that directory. */
    private static function adminSources(): array
    {
        $root = dirname(__DIR__, 2).'/app/Filament';
        $sources = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php') {
                $sources[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }
        ksort($sources);

        return $sources;
    }

    /** @return list<PhpToken> */
    private static function tokens(string $source): array
    {
        return array_values(array_filter(PhpToken::tokenize($source), fn (PhpToken $token): bool => ! $token->isIgnorable()));
    }

    /**
     * A method called at the chain's own level, with "()" when it takes no arguments and "(...)" when it takes any.
     *
     * @param  list<PhpToken>  $tokens
     */
    private static function call(array $tokens, int $name): string
    {
        return $tokens[$name]->text.(($tokens[$name + 1]->text ?? '') === '(' && ($tokens[$name + 2]->text ?? '') === ')' ? '()' : '(...)');
    }

    /** @return list<array{int, list<string>}> Each FileUpload::make() call's line and the methods chained on it, in order. */
    private static function uploadChains(string $source): array
    {
        $tokens = self::tokens($source);
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
                if (in_array($text, ['(', '[', '{', '#['], true)) {
                    $depth++;
                } elseif (in_array($text, [')', ']', '}'], true)) {
                    if (--$depth < 0) {
                        break;
                    }
                } elseif ($depth === 0 && in_array($text, [',', ';'], true)) {
                    break;
                } elseif ($depth === 0 && in_array($text, ['->', '?->'], true) && isset($tokens[$next + 1])) {
                    $methods[] = self::call($tokens, $next + 1);
                }
            }
            $chains[] = [$token->line, $methods];
        }

        return $chains;
    }

    /** @return list<int> The line of every preventFilePathTampering call that passes arguments, wherever it appears. */
    private static function guardCallsWithArguments(string $source): array
    {
        $tokens = self::tokens($source);
        $lines = [];
        foreach ($tokens as $index => $token) {
            if ($token->text === 'preventFilePathTampering' && in_array($tokens[$index - 1]->text ?? '', ['->', '?->', '::'], true)
                && self::call($tokens, $index) === 'preventFilePathTampering(...)') {
                $lines[] = $token->line;
            }
        }

        return $lines;
    }
}
