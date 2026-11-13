<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\LegacyRedirectFixtures as F;
use Tests\TestCase;

class LegacyRedirectCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir().'/legacy-redirect-command-'.Str::uuid();
    }

    protected function tearDown(): void
    {
        F::remove($this->directory);
        parent::tearDown();
    }

    public function test_the_shipped_map_prints_the_reconciliation_and_passes_but_fails_the_cutover_gate_while_rows_are_unresolved(): void
    {
        $this->artisan('vasey:redirects-validate')
            ->expectsOutputToContain('activation flag legacy-redirects.enabled is off')
            ->expectsOutputToContain('Inventory rows: 20 · map entries: 20 · redirect: 6 · gone: 0 · retained: 6 · unresolved: 8')
            ->expectsOutputToContain('Query strings: dropped on redirect. Matching: exact path only.')
            ->expectsOutputToContain('RETAINED URL-010 /blog served by editorial.show')
            ->expectsOutputToContain('REDIRECT URL-002 /music/tracks -> / (301)')
            ->expectsOutputToContain('RETAINED URL-006 /services served by services.index')
            ->expectsOutputToContain('REDIRECT URL-013 /licensing-info -> /legal/licenses (301)')
            ->expectsOutputToContain('REDIRECT URL-014 /terms-of-use -> /legal/terms (301)')
            ->expectsOutputToContain('REDIRECT URL-015 /privacy-policy -> /legal/privacy (301)')
            ->expectsOutputToContain('UNRESOLVED URL-016 /account/purchases: Crawl 2026-11-10')
            ->expectsOutputToContain('Map valid: no errors.')
            ->assertSuccessful();
        $this->artisan('vasey:redirects-validate', ['--require-resolved' => true])
            ->expectsOutputToContain('7 inventory row(s) are unresolved; the cutover gate requires none.')
            ->assertFailed();
    }

    public function test_errors_and_unreadable_files_fail_with_each_problem_listed(): void
    {
        $files = F::write($this->directory, [
            F::redirect('URL-001', '/legacy-fixture/a', ['type' => 'external', 'url' => 'https://attacker.example/x']),
            F::redirect('URL-002', '/legacy-fixture/b', ['type' => 'route', 'name' => 'home']),
            F::gone('URL-003', '/legacy-fixture/c'),
        ]);
        $this->artisan('vasey:redirects-validate', ['--map' => $files['map'], '--inventory' => $files['inventory']])
            ->expectsOutputToContain('redirect: 1 · gone: 1 · retained: 0 · unresolved: 0')
            ->expectsOutputToContain('REDIRECT URL-002 /legacy-fixture/b -> / (301)')
            ->expectsOutputToContain('GONE     URL-003 /legacy-fixture/c (410)')
            ->expectsOutputToContain('ERROR URL-001 target: external host attacker.example is not on the map\'s external_hosts allowlist (open redirect refused).')
            ->expectsOutputToContain('1 error(s): the map is not fit for activation.')
            ->assertFailed();
        file_put_contents($files['map'], '[]');
        $this->artisan('vasey:redirects-validate', ['--map' => $files['map'], '--inventory' => $files['inventory']])
            ->expectsOutputToContain('Legacy redirect map or inventory refused:')->assertFailed();
        $this->artisan('vasey:redirects-validate', ['--inventory' => $this->directory.'/absent.csv'])
            ->expectsOutputToContain('ERROR The route inventory is missing, unreadable or larger than 1 MiB.')->assertFailed();
    }
}
