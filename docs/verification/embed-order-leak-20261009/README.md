# Embed order leak: Livewire asset-injection state across tests

Development evidence from the Claude Code harness, 2026-10-09. This is not Foundation or final acceptance.

## Failure

`PublicTrackEmbedTest` passes alone but fails 4 cases when an earlier test in the same PHPUnit process renders a
Livewire component through a real HTTP request. Livewire keeps `SupportAutoInjectedAssets::$hasRenderedAComponentThisRequest`
(and `$forceAssetInjection`) in static properties. They outlive each test's application. Its `RequestHandled` listener then
injects Livewire's `<style>` and `<script>` into every later 200 `text/html` response, including the script-free public embed.

`Livewire::test()` is not the trigger: its initial and subsequent renders call `flushState()` themselves. An ordered
`OperatorMfaTest` → `PublicTrackEmbedTest` run passed on the unfixed base. The trigger is a component rendered outside
`Livewire::test()`, such as a Filament page fetched with `$this->get()`.

**Finding the trigger.** A temporary, uncommitted trace in `tests/TestCase.php::setUp()` printed the static flag at the
start of each test (`evidence/flag-trace-scan.txt`, filtered to the trace lines). The first test to start with the flag set
followed `MembershipAdministrationActionTest::test_private_http_pages_require_an_operator_and_current_enrolled_mfa`. The
scan was stopped after that point; it is diagnosis, not a census of every leaking test.

## Fix

`tests/TestCase.php::setUp()` calls `Livewire::flushState()` after `parent::setUp()` (candidate `9b51c641`, unchanged).
Test-only; no application code changes.

`tests/Feature/LivewireStateIsolationTest.php` (new) is the regression test. Its first case leaves both flags set; the
second, which depends on the first so it always runs after it, asserts that the next test starts with both cleared.

## Results

All runs: PHP 8.4.26, PHPUnit 12.5.34, SQLite in memory, `public/build` absent, from the worktree root with
`php -r '$GLOBALS["_composer_autoload_path"]=getcwd()."/vendor/autoload.php"; require "vendor/phpunit/phpunit/phpunit";' -- --colors=never --do-not-cache-result <args>`.
Source: `875b7edc` (the branch merged with main `49489697`).

| Run | Source | Result | Evidence |
| --- | --- | --- | --- |
| Ordered red: `MembershipAdministrationActionTest` then `PublicTrackEmbedTest`, one process, `executionOrder="default"` | `875b7edc` with `tests/TestCase.php` replaced by main's (no flush) | 42 tests, **4 failures**, rc 1: the same 4 cases as reported (script-free embed: one `<script>`; the three asset-origin cases: `<style>` + `<script>`) | `evidence/red-ordered.txt`, `evidence/ordered-phpunit.xml` |
| Ordered green, same configuration | `875b7edc` clean | 42 tests, 1,456 assertions, rc 0 | `evidence/green-ordered.txt` |
| Regression test red | new test file + main's `tests/TestCase.php` | 2 tests, 1 failure (the dependent case), rc 1 | `evidence/regression-red.txt` |
| Regression test green | new test file + the fix | 2 tests, 3 assertions, rc 0 | `evidence/regression-green.txt` |
| Pint `--test` | `tests/Feature/LivewireStateIsolationTest.php` | pass | — |

`ordered-phpunit.xml` copies `phpunit.xml`'s `<php>` block verbatim (checked with `diff`) and lists the two files in
order. Its paths name the harness worktree.

**Known, not changed here.** Pint's `ordered_imports` rule already fails on `tests/TestCase.php` on main (the
`Illuminate\Foundation` import precedes `Illuminate\Filesystem`). The fix adds one import in order and leaves the
existing order alone (scope).

## Not tested

The full suite or the CI shard ordering. Other leaking tests may exist; the flush covers any of them because it runs
before every test that extends `Tests\TestCase`. Tests that extend PHPUnit's `TestCase` directly do not boot Laravel and
render no HTML through it.
