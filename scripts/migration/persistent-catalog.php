<?php

// Internal trusted console for persistent-catalog.mjs. No public route or password option exists.
use App\Domain\Migration\CatalogOnboarding\CatalogDatabaseEvidence;
use App\Domain\Migration\CatalogOnboarding\CatalogDraftImporter;
use App\Domain\Migration\CatalogOnboarding\PrivateSourceFiles;
use App\Domain\Migration\CatalogOnboarding\ProtectedCatalogReport;
use App\Models\User;
use App\Support\CanonicalJson;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Process\Process;

function catalogRequire(bool $condition): void
{
    if (! $condition) {
        throw new RuntimeException('catalog_operation_invalid');
    }
}

function catalogSecret(string $prompt): string
{
    catalogRequire(function_exists('stream_isatty') && stream_isatty(STDIN));
    $input = new ArrayInput([]);
    $input->setInteractive(true);
    $question = new Question($prompt);
    $question->setHidden(true);
    $question->setHiddenFallback(false);
    $answer = (new QuestionHelper)->ask($input, new ConsoleOutput, $question);
    catalogRequire(is_string($answer) && strlen($answer) >= 1 && strlen($answer) <= 1024);

    return $answer;
}

umask(0077);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
try {
    catalogRequire(PHP_SAPI === 'cli' && count($argv) === 7 && in_array($argv[1], ['review', 'apply'], true)
        && preg_match('/\A[1-9][0-9]{0,14}\z/D', $argv[4]) === 1 && (int) $argv[4] <= PHP_INT_MAX
        && preg_match('/\A(?:[1-9]|1[0-9]|2[0-5])\z/D', $argv[6]) === 1);
    [$script, $command, $sourceDirectory, $reportPath, $actorId, $expectedReview, $limit] = $argv;
    catalogRequire(($command === 'review' && $expectedReview === '-') || ($command === 'apply' && preg_match('/\A[a-f0-9]{64}\z/D', $expectedReview) === 1));
    // Reuse the exact retained installation, live OS lease, own autoloader and fail-closed effective configuration proof.
    $retainedArguments = $argv;
    $argv = [__FILE__, 'verify'];
    require __DIR__.'/../dev/persistent-content-bootstrap.php';
    $argv = $retainedArguments;
    [$directory, $identity] = PersistentContentBootstrap::guard(['ready']);
    $release = ['commit' => getenv('VASEY_CATALOG_TARGET_COMMIT'), 'tree' => getenv('VASEY_CATALOG_TARGET_TREE'),
        'schema_hash' => getenv('VASEY_CATALOG_TARGET_SCHEMA')];
    catalogRequire($release['schema_hash'] === $identity['schema_hash']);
    $source = (new PrivateSourceFiles)->snapshot($sourceDirectory);
    $reports = new ProtectedCatalogReport;
    $retainedReport = $command === 'apply' ? $reports->read($reportPath, $identity['app_key']) : null;
    if ($command === 'review') {
        (new PrivateSourceFiles)->privateDirectory(dirname($reportPath));
        catalogRequire(@lstat($reportPath) === false);
    }
    $password = catalogSecret('Staff password (hidden): ');
    // Credential lookup is current after the interactive wait, never a retained pre-prompt password hash.
    $actor = User::query()->find((int) $actorId);
    catalogRequire($actor !== null && Hash::check($password, $actor->getAuthPassword()));
    unset($password);
    $authenticated = (new CatalogDatabaseEvidence)->rows('users')[$actor->id] ?? null;
    catalogRequire($authenticated !== null && CanonicalJson::hash($authenticated['attributes']) === CanonicalJson::hash($actor->getAttributes()));
    $panel = Filament::getPanel('admin');
    catalogRequire($panel !== null);
    if ($panel->isMultiFactorAuthenticationRequired()) {
        $providers = array_values(array_filter($panel->getMultiFactorAuthenticationProviders(), static fn ($provider): bool => $provider instanceof AppAuthentication && $provider->isEnabled($actor)));
        catalogRequire(count($providers) === 1);
        $code = catalogSecret('Current authenticator code (hidden): ');
        catalogRequire($providers[0]->verifyCode($code, $providers[0]->getSecret($actor), shouldPreventCodeReuse: true));
        unset($code);
    }
    $verify = function () use ($authenticated, $actor, $reports, $retainedReport, $release): void {
        $current = (new CatalogDatabaseEvidence)->rows('users')[$actor->id] ?? null;
        catalogRequire($current !== null && $current['sha256'] === $authenticated['sha256']);
        if ($retainedReport !== null) {
            $reports->unchanged($retainedReport['proof']);
        }
        PersistentContentBootstrap::guard(['ready']);
        $process = new Process(['node', 'scripts/migration/persistent-catalog.mjs', 'verify-release',
            '--expected-target-sha', $release['commit'], '--expected-tree', $release['tree'], '--expected-schema', $release['schema_hash']],
            getenv('VASEY_CONTENT_CHECKOUT'), timeout: 30);
        $process->run();
        catalogRequire($process->isSuccessful());
    };
    $importer = new CatalogDraftImporter;
    if ($command === 'review') {
        $review = $importer->review($source, $release, $actor, $verify);
        $reports->write($reportPath, $review, $identity['app_key']);
        fwrite(STDOUT, CanonicalJson::encode(['mode' => 'private-catalog-review', 'review_sha256' => $review['review_sha256'],
            'counts' => $review['counts'], 'database_writes' => 0])."\n");
    } else {
        $result = $importer->apply($source, $release, $retainedReport['review'], $expectedReview, (int) $limit, $actor, $verify);
        fwrite(STDOUT, CanonicalJson::encode(['mode' => 'private-catalog-apply', 'review_sha256' => $result['review_sha256'],
            'processed' => $result['processed'], 'total' => $result['total'], 'complete' => $result['complete'],
            'created' => count($result['created_track_ids']), 'retained_mappings' => $result['retained_mappings']])."\n");
    }
} catch (Throwable) {
    fwrite(STDERR, "Private catalog operation refused or failed. Retained source, review and workspace files were not reset or removed.\n");
    exit(1);
}
