<?php

use App\Domain\Catalog\PublishOffer;
use App\Domain\Catalog\PublishTrack;
use App\Domain\Catalog\SaveOfferDraft;
use App\Domain\Catalog\SaveTrackMetadata;
use App\Domain\Commerce\Checkout\HostedCheckout;
use App\Domain\Commerce\CreateQuote;
use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\CheckoutIntent;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Commerce\Models\VerifiedPayment;
use App\Domain\Commerce\Orders\PrepareOrder;
use App\Domain\Commerce\Orders\ReadOrder;
use App\Domain\Commerce\Orders\ReviewOrder;
use App\Domain\Commerce\Payments\StripeCheckoutGateway;
use App\Domain\Commerce\Payments\StripePaymentGateway;
use App\Domain\Commerce\Payments\VerifyTestPayment;
use App\Domain\Commerce\PriceQuote;
use App\Domain\Commerce\QuoteException;
use App\Domain\Rights\Models\RightsDeclaration;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Support\CheckoutFixtures;
use Tests\Support\FinalizationFixtures;
use Tests\Support\LicenseFixtures;
use Tests\Support\MediaFixtures;
use Tests\Support\PaymentFixtures;

// CLI-only proof fixtures: no route invokes this file, no existing installation/database is reset.
try {
    $directory = getenv('VASEY_BROWSER_DIRECTORY');
    $marker = getenv('VASEY_BROWSER_EXCEPTION_MARKER');
    $password = getenv('VASEY_BROWSER_PASSWORD');
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    $phase = $argv[3] ?? null;
    if (PHP_SAPI !== 'cli' || ! is_string($directory) || is_link($directory) || realpath($directory) !== $directory
        || realpath(dirname($directory)) !== realpath(sys_get_temp_dir()) || preg_match('/\Avasey-browser-[A-Za-z0-9]+\z/D', basename($directory)) !== 1
        || getenv('APP_ENV') !== 'local' || getenv('APP_DEBUG') !== 'false' || getenv('APP_URL') !== 'http://127.0.0.1:8173'
        || getenv('LARAVEL_STORAGE_PATH') !== $directory || getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_URL') !== ''
        || getenv('DB_DATABASE') !== $directory.'/database.sqlite' || getenv('DB_FOREIGN_KEYS') !== 'true'
        || ! is_file($directory.'/database.sqlite') || is_link($directory.'/database.sqlite') || realpath($directory.'/database.sqlite') !== $directory.'/database.sqlite'
        || filesize($directory.'/database.sqlite') === 0 || getenv('APP_CONFIG_CACHE') !== $directory.'/config.php' || file_exists($directory.'/config.php') || is_link($directory.'/config.php')
        || getenv('APP_ROUTES_CACHE') !== $directory.'/routes.php' || file_exists($directory.'/routes.php') || is_link($directory.'/routes.php')
        || getenv('APP_EVENTS_CACHE') !== $directory.'/events.php' || file_exists($directory.'/events.php') || is_link($directory.'/events.php')
        || getenv('CACHE_STORE') !== 'file' || getenv('SESSION_DRIVER') !== 'file' || getenv('SESSION_ENCRYPT') !== 'true'
        || getenv('QUEUE_CONNECTION') !== 'sync' || getenv('MAIL_MAILER') !== 'array' || getenv('FILESYSTEM_DISK') !== 'local'
        || getenv('STRIPE_ACCOUNT_ID') !== 'acct_SYNTHETICONLY' || getenv('STRIPE_MODE') !== 'test'
        || getenv('STRIPE_TEST_SECRET_KEY') !== '' || getenv('STRIPE_WEBHOOK_SECRET') !== ''
        || getenv('STRIPE_TEST_CHECKOUT_ENABLED') !== 'false' || getenv('STRIPE_TEST_PAYMENT_PROCESSING_ENABLED') !== 'false'
        || getenv('STRIPE_TEST_FINALIZATION_ENABLED') !== 'false' || getenv('STRIPE_WEBHOOK_ENABLED') !== 'false'
        || ! is_string($marker) || preg_match('/\A[a-f0-9]{64}\z/D', $marker) !== 1 || ! is_string($password) || strlen($password) < 40
        || ! in_array($project, ['chromium-desktop', 'webkit-mobile'], true) || ! in_array($mode, ['prepare', 'verify', 'withdraw', 'restore'], true)
        || count($argv) !== ($mode === 'verify' ? 4 : 3) || ($mode === 'verify' && ! in_array($phase, ['first', 'reopened', 'attention', 'withdrawn', 'restored'], true))) {
        throw new RuntimeException('Not an isolated exception-inspection browser run.');
    }
    foreach (['framework/views', 'framework/sessions', 'framework/cache/data', 'logs', 'app/private'] as $child) {
        if (is_link($directory.'/'.$child) || realpath($directory.'/'.$child) !== $directory.'/'.$child) {
            throw new RuntimeException('Unsafe temporary storage path.');
        }
    }
    foreach (['fixtures.json', 'exception-inspection-fixture-marker.json'] as $file) {
        if (! is_file($directory.'/'.$file) || is_link($directory.'/'.$file) || realpath($directory.'/'.$file) !== $directory.'/'.$file) {
            throw new RuntimeException('Missing fixture identity.');
        }
    }
    $fixtures = json_decode(file_get_contents($directory.'/fixtures.json'), true, 16, JSON_THROW_ON_ERROR);
    $identity = json_decode(file_get_contents($directory.'/exception-inspection-fixture-marker.json'), true, 8, JSON_THROW_ON_ERROR);
    if (! is_array($fixtures) || array_keys($fixtures) !== ['chromium-desktop', 'webkit-mobile']
        || $identity !== ['purpose' => 'retained-exception-native', 'marker' => $marker, 'database' => $directory.'/database.sqlite',
            'origin' => 'http://127.0.0.1:8173', 'baseOperatorId' => 1, 'account' => 'acct_SYNTHETICONLY']) {
        throw new RuntimeException('Fixture identity mismatch.');
    }
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    if (! $app->environment('local') || config('app.url') !== 'http://127.0.0.1:8173' || config('app.debug') !== false
        || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $directory.'/database.sqlite'
        || ! in_array(config('database.connections.sqlite.url'), [null, ''], true)
        || DB::getDriverName() !== 'sqlite' || (int) DB::selectOne('PRAGMA foreign_keys')->foreign_keys !== 1
        || storage_path() !== $directory || config('filesystems.disks.local.driver') !== 'local' || config('filesystems.disks.local.root') !== $directory.'/app/private'
        || config('session.driver') !== 'file' || config('session.files') !== $directory.'/framework/sessions' || config('session.encrypt') !== true
        || config('cache.default') !== 'file' || config('cache.stores.file.driver') !== 'file'
        || config('cache.stores.file.path') !== $directory.'/framework/cache/data' || config('cache.stores.file.lock_path') !== $directory.'/framework/cache/data'
        || config('queue.default') !== 'sync' || config('mail.default') !== 'array' || config('payments.stripe.account_id') !== CheckoutFixtures::ACCOUNT
        || config('payments.stripe.mode') !== 'test' || ! in_array(config('payments.stripe.secret_key'), [null, ''], true)
        || ! in_array(config('payments.stripe.webhook_secret'), [null, ''], true) || config('payments.stripe.checkout_enabled') !== false
        || config('payments.stripe.processing_enabled') !== false || config('payments.stripe.finalization_enabled') !== false || config('payments.stripe.webhook_enabled') !== false) {
        throw new RuntimeException('Effective paths or disabled payment configuration do not match.');
    }
    $databases = DB::select('PRAGMA database_list');
    if (collect($databases)->firstWhere('name', 'main')?->file !== $directory.'/database.sqlite'
        || collect($databases)->contains(fn ($db): bool => ! in_array($db->name, ['main', 'temp'], true))) {
        throw new RuntimeException('Unexpected database attachment.');
    }
    $baseOperator = User::findOrFail(1);
    if ($baseOperator->name !== 'Synthetic Browser Operator' || $baseOperator->email !== 'browser-operator@example.test'
        || ! Gate::forUser($baseOperator)->allows('administer-catalog') || ! AdminMultiFactor::satisfiedBy($baseOperator)) {
        throw new RuntimeException('Base operator does not match.');
    }
    $path = $directory.'/exception-inspection-'.$project.'.json';
    if (is_link($path)) {
        throw new RuntimeException('Unsafe fixture metadata path.');
    }

    if ($mode === 'prepare') {
        if (file_exists($path)) {
            throw new RuntimeException('Exception fixture already prepared.');
        }
        $beforeRows = financialRows();
        $triggerHash = triggerHash();
        $operator = provision($kernel, 'Synthetic exception inspector '.$project, 'exception-inspector-'.$project.'@example.test', $password);
        $reviewer = provision($kernel, 'Synthetic exception reviewer '.$project, 'exception-reviewer-'.$project.'@example.test', $password);
        $records = [];
        try {
            // Only this guarded CLI process enters testing for the existing nonbinding media/provider fixtures.
            $app->detectEnvironment(fn () => 'testing');
            FinalizationFixtures::configure();
            foreach (['verified', 'attention'] as $case) {
                $records[$case] = prepareRecord($project, $case, $marker, $baseOperator, $reviewer);
            }
        } finally {
            Carbon::setTestNow();
            CarbonImmutable::setTestNow();
            $app->detectEnvironment(fn () => 'local');
        }
        corruptOnlyDesignatedHash($records['attention']);
        if (triggerHash() !== $triggerHash) {
            throw new RuntimeException('A guard definition changed.');
        }
        $afterRows = financialRows();
        foreach ($beforeRows as $table => $rows) {
            foreach ($rows as $id => $row) {
                if (($afterRows[$table][$id] ?? null) !== $row) {
                    throw new RuntimeException('Pre-existing evidence changed.');
                }
            }
        }
        $fixture = ['purpose' => 'retained-exception-native', 'marker' => $marker, 'project' => $project,
            'operatorId' => $operator->id, 'operatorName' => $operator->name, 'operatorEmail' => $operator->email,
            'operatorVerifiedAt' => $operator->getRawOriginal('email_verified_at'), 'operatorAdmin' => true,
            'records' => $records, 'financialHash' => hash('sha256', json_encode($afterRows, JSON_THROW_ON_ERROR)), 'triggerHash' => $triggerHash,
            'state' => 'prepared', 'auditCounts' => ['verified' => 0, 'attention' => 0]];
        writeFixture($path, $fixture, true);
        echo json_encode(['operatorEmail' => $operator->email, 'verifiedId' => $records['verified']['publicId'],
            'attentionId' => $records['attention']['publicId'], 'orderId' => $records['verified']['orderPublicId'],
            'privateMarkers' => array_merge($records['verified']['privateMarkers'], $records['attention']['privateMarkers'])], JSON_THROW_ON_ERROR)."\n";
    } else {
        if (! is_file($path) || realpath($path) !== $path) {
            throw new RuntimeException('Missing prepared fixture.');
        }
        $fixture = json_decode(file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        if (($fixture['purpose'] ?? null) !== 'retained-exception-native' || ($fixture['marker'] ?? null) !== $marker || ($fixture['project'] ?? null) !== $project
            || ($fixture['operatorEmail'] ?? null) !== 'exception-inspector-'.$project.'@example.test'
            || ($fixture['operatorName'] ?? null) !== 'Synthetic exception inspector '.$project || ! is_int($fixture['operatorId'] ?? null)
            || ($fixture['operatorAdmin'] ?? null) !== true || array_keys($fixture['records'] ?? []) !== ['verified', 'attention']) {
            throw new RuntimeException('Prepared fixture identity mismatch.');
        }
        $operator = User::findOrFail($fixture['operatorId']);
        if ($operator->name !== $fixture['operatorName'] || $operator->email !== $fixture['operatorEmail']
            || $operator->getRawOriginal('email_verified_at') !== $fixture['operatorVerifiedAt']) {
            throw new RuntimeException('Owned operator changed unexpectedly.');
        }
        assertEvidenceUnchanged($fixture);
        $counts = auditCounts($fixture);
        if ($mode === 'withdraw') {
            if ($fixture['state'] !== 'attention' || $operator->is_admin !== true) {
                throw new RuntimeException('Unexpected withdrawal state.');
            }
            DB::transaction(function () use ($operator, $baseOperator): void {
                $owned = User::whereKey($operator->id)->lockForUpdate()->firstOrFail();
                $owned->forceFill(['is_admin' => false])->save();
                AuditEvent::record('access.operator.browser_fixture_withdrawn', $owned, ['purpose' => 'retained-exception-native', 'test_only' => true], $baseOperator->id);
            });
            $fixture['state'] = 'withdrawn';
            $fixture['auditCounts'] = $counts;
            writeFixture($path, $fixture);
            echo "{\"withdrawn\":true}\n";
        } elseif ($mode === 'restore') {
            // Restore only this exact dedicated fixture operator; the shared operator and business evidence are untouched.
            if ($operator->is_admin !== $fixture['operatorAdmin']) {
                DB::transaction(function () use ($operator, $baseOperator, $fixture): void {
                    $owned = User::whereKey($operator->id)->lockForUpdate()->firstOrFail();
                    $owned->forceFill(['is_admin' => $fixture['operatorAdmin']])->save();
                    AuditEvent::record('access.operator.browser_fixture_restored', $owned, ['purpose' => 'retained-exception-native', 'test_only' => true], $baseOperator->id);
                });
            }
            $fixture['state'] = 'restored';
            writeFixture($path, $fixture);
            assertEvidenceUnchanged($fixture);
            echo "{\"restored\":true,\"evidenceUnchanged\":true,\"guardsRestored\":true}\n";
        } else {
            $expectedPrevious = ['first' => 'prepared', 'reopened' => 'first', 'attention' => 'reopened', 'withdrawn' => 'withdrawn', 'restored' => 'restored'][$phase];
            if ($fixture['state'] !== $expectedPrevious || ($phase === 'withdrawn' ? $operator->is_admin !== false : $operator->is_admin !== true)
                || ($phase === 'first' && $counts['verified'] < 1) || ($phase === 'reopened' && $counts['verified'] <= $fixture['auditCounts']['verified'])
                || (in_array($phase, ['first', 'reopened'], true) && $counts['attention'] !== 0)
                || ($phase === 'attention' && $counts['attention'] < 1) || ($phase === 'withdrawn' && $counts !== $fixture['auditCounts'])
                || $counts['verified'] < $fixture['auditCounts']['verified'] || $counts['attention'] < $fixture['auditCounts']['attention']) {
                throw new RuntimeException('Inspection/denial audit did not match the journey.');
            }
            $fixture['state'] = $phase;
            $fixture['auditCounts'] = $counts;
            writeFixture($path, $fixture);
            echo json_encode(['phase' => $phase, 'inspectionAudits' => $counts, 'evidenceUnchanged' => true, 'guardsRestored' => true], JSON_THROW_ON_ERROR)."\n";
        }
    }
} catch (Throwable) {
    fwrite(STDERR, "Isolated exception-inspection fixture operation failed; no private details are printed.\n");
    exit(1);
}

function provision(Kernel $kernel, string $name, string $email, string $password): User
{
    $tester = new CommandTester($kernel->all()['vasey:create-admin']);
    $tester->setInputs([$name, $email, $password]);
    if ($tester->execute([], ['interactive' => true]) !== 0) {
        throw new RuntimeException('Synthetic operator provisioning failed.');
    }
    $user = User::where('email', $email)->sole();
    if ($user->name !== $name || ! Gate::forUser($user)->allows('administer-catalog')) {
        throw new RuntimeException('Synthetic account mismatch.');
    }

    return $user;
}

function prepareRecord(string $project, string $case, string $marker, User $actor, User $reviewer): array
{
    $suffix = str_replace('-', '', $project).$case.substr($marker, 0, 12);
    $track = app(SaveTrackMetadata::class)->handle(null, ['title' => 'Synthetic exception '.$project.' '.$case,
        'slug' => 'synthetic-exception-'.$project.'-'.$case.'-'.substr($marker, 0, 12), 'artist' => 'Synthetic test only', 'bpm' => 90, 'musical_key' => 'C minor', 'genre' => 'Test'], $actor);
    RightsDeclaration::create(['track_id' => $track->id, 'provenance_reference' => 'SYNTHETIC-BROWSER-EXCEPTION',
        'sample_disclosure' => 'Nonbinding synthetic fixture', 'status' => 'verified', 'verified_by' => $actor->id, 'verified_at' => now()]);
    $media = MediaFixtures::readyTrackMedia($track, $actor);
    $license = LicenseFixtures::published($actor, $reviewer);
    $offer = app(SaveOfferDraft::class)->handle(null, ['track_id' => $track->id, 'license_version_id' => $license->id,
        'price_minor' => 4999, 'currency' => 'USD', 'deliverable_asset_ids' => [$media['master_wav']->id]], $actor);
    $revision = app(PublishOffer::class)->handle($offer, $actor);
    $track = app(PublishTrack::class)->handle($track, $actor);
    try {
        $inventory = app(ManageRightsScope::class);
        $scope = $inventory->register('synthetic-browser-'.$suffix, 'SYNTHETIC-BROWSER-ONLY', $actor);
        $inventory->link($scope->id, $revision->id, 'SYNTHETIC-BROWSER-ONLY', $actor);
        $owner = hash_hmac('sha256', $suffix, $marker);
        $quote = app(CreateQuote::class)->handle($owner, (string) Str::uuid(), [['trackId' => $track->id, 'offerId' => $offer->id,
            'licenseVersionId' => $license->id, 'offerRevisionId' => $revision->id]]);
        app(PriceQuote::class)->create($quote->public_id, $owner);
        $review = app(ReviewOrder::class)->handle($quote->public_id, $owner);
        $buyer = ['legalName' => 'Synthetic private exception buyer '.$project.' '.$case, 'email' => 'exception-buyer-'.$project.'-'.$case.'@example.test'];
        $order = app(PrepareOrder::class)->handle($owner, (string) Str::uuid(), ['quoteId' => $quote->public_id, 'reviewHash' => $review['reviewHash'], 'buyer' => $buyer, 'accepted' => true]);
        $gateway = PaymentFixtures::gateway();
        $gateway->onCreate = fn (array $params): array => CheckoutFixtures::session($params, 'cs_test_BROWSER'.$suffix);
        app()->instance(StripeCheckoutGateway::class, $gateway);
        app()->instance(StripePaymentGateway::class, $gateway);
        app(HostedCheckout::class)->start($order->public_id, $owner);
        $gateway->session['status'] = 'complete';
        $gateway->session['payment_status'] = 'paid';
        $gateway->session['url'] = null;
        $gateway->session['payment_intent'] = 'pi_BROWSER'.$suffix;
        $gateway->payment = PaymentFixtures::payment($gateway->session);
        $gateway->payment['id'] = $gateway->session['payment_intent'];
        $intent = CheckoutIntent::where('order_id', $order->id)->sole();
        $cutoff = $order->attempt()->sole()->expires_at;
        Carbon::setTestNow($cutoff);
        CarbonImmutable::setTestNow($cutoff);
        if (app(VerifyTestPayment::class)->reconcile($intent) !== 'awaiting_finalization') {
            throw new RuntimeException('Synthetic confirmation failed.');
        }
        $payment = VerifiedPayment::where('order_id', $order->id)->sole();
        if (app(FinalizeTestPayment::class)->handle($payment->id) !== 'paid_exception') {
            throw new RuntimeException('Synthetic exception failed.');
        }
        $finalization = OrderFinalization::where('order_id', $order->id)->sole();
        app(ReadOrder::class)->verify($order);

        return ['id' => $finalization->id, 'publicId' => $finalization->public_id, 'orderId' => $order->id, 'orderPublicId' => $order->public_id,
            'originalHash' => $finalization->evidence_hash, 'privateMarkers' => [$buyer['legalName'], $buyer['email'], $owner,
                $payment->provider_payment_intent_id, $gateway->session['id'], $intent->idempotency_key,
                $media['master_wav']->storage_path, $finalization->evidence_hash]];
    } finally {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        app(PublishTrack::class)->unpublish($track, $actor);
    }
}

function corruptOnlyDesignatedHash(array $record): void
{
    DB::transaction(function () use ($record): void {
        $row = OrderFinalization::whereKey($record['id'])->where('public_id', $record['publicId'])->where('mode', 'test')->where('outcome', 'paid_exception')->sole();
        if ($row->order_id !== $record['orderId'] || $row->evidence_hash !== $record['originalHash'] || $row->payment->account_id !== CheckoutFixtures::ACCOUNT) {
            throw new RuntimeException('Corruption target is not owned synthetic evidence.');
        }
        $name = 'order_finalizations_immutable_update';
        $sql = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name])?->sql;
        if (! is_string($sql) || ! str_starts_with($sql, 'CREATE TRIGGER '.$name.' ')) {
            throw new RuntimeException('Unexpected immutable guard definition.');
        }
        DB::unprepared('DROP TRIGGER '.$name);
        try {
            if (DB::table('order_finalizations')->where('id', $row->id)->where('public_id', $row->public_id)->where('evidence_hash', $record['originalHash'])
                ->update(['evidence_hash' => str_repeat('0', 64)]) !== 1) {
                throw new RuntimeException('Synthetic corruption did not match one record.');
            }
        } finally {
            DB::unprepared($sql);
        }
        if (DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name])?->sql !== $sql) {
            throw new RuntimeException('Exact guard was not restored.');
        }
    });
}

function financialRows(): array
{
    $result = [];
    foreach (['quotes', 'quote_lines', 'quote_pricings', 'orders', 'order_lines', 'order_attempts', 'checkout_intents', 'checkout_sessions',
        'checkout_observations', 'payment_observations', 'verified_payments', 'order_finalizations', 'inventory_reservations', 'inventory_claims',
        'promotion_campaigns', 'promotion_uses', 'license_grants', 'pending_entitlements', 'fulfillment_outbox', 'exclusive_sales'] as $table) {
        $result[$table] = DB::table($table)->orderBy('id')->get()->keyBy('id')->map(fn ($row): string => json_encode($row, JSON_THROW_ON_ERROR))->all();
    }

    return $result;
}

function triggerHash(): string
{
    return hash('sha256', json_encode(DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'trigger' ORDER BY name"), JSON_THROW_ON_ERROR));
}

function assertEvidenceUnchanged(array $fixture): void
{
    if (triggerHash() !== $fixture['triggerHash'] || hash('sha256', json_encode(financialRows(), JSON_THROW_ON_ERROR)) !== $fixture['financialHash']) {
        throw new RuntimeException('Business evidence or guards changed.');
    }
    foreach ($fixture['records'] as $case => $record) {
        $finalization = OrderFinalization::whereKey($record['id'])->where('public_id', $record['publicId'])->sole();
        if ($finalization->order_id !== $record['orderId'] || $finalization->mode !== 'test' || $finalization->outcome !== 'paid_exception'
            || $finalization->payment->account_id !== CheckoutFixtures::ACCOUNT) {
            throw new RuntimeException('Owned record binding changed.');
        }
        try {
            app(ReadOrder::class)->verify($finalization->order);
            if ($case !== 'verified') {
                throw new RuntimeException('Corrupt fixture was accepted.');
            }
        } catch (QuoteException $error) {
            if ($case !== 'attention' || $error->errorCode !== 'ORDER_CHANGED') {
                throw $error;
            }
        }
    }
}

function auditCounts(array $fixture): array
{
    $counts = [];
    foreach ($fixture['records'] as $case => $record) {
        $events = AuditEvent::where('subject_type', OrderFinalization::class)->where('subject_id', $record['id'])->where('action', 'commerce.payment_exception.inspected')->get();
        foreach ($events as $event) {
            if ($event->actor_id !== $fixture['operatorId'] || $event->context !== ['finalization_id' => $record['publicId'],
                'result' => $case === 'verified' ? 'verified' : 'attention', 'test_only' => true]) {
                throw new RuntimeException('Unexpected inspection audit.');
            }
        }
        $counts[$case] = $events->count();
    }

    return $counts;
}

function writeFixture(string $path, array $fixture, bool $exclusive = false): void
{
    umask(0077);
    $json = json_encode($fixture, JSON_THROW_ON_ERROR);
    if ($exclusive) {
        $stream = fopen($path, 'x');
        if ($stream === false) {
            throw new RuntimeException('Unable to retain fixture metadata.');
        }
        try {
            if (! chmod($path, 0600) || fwrite($stream, $json) !== strlen($json) || ! fflush($stream)) {
                throw new RuntimeException('Unable to retain fixture metadata.');
            }
        } finally {
            fclose($stream);
        }
    } elseif (! is_file($path) || is_link($path) || realpath($path) !== $path || file_put_contents($path, $json, LOCK_EX) !== strlen($json)) {
        throw new RuntimeException('Unable to update owned fixture metadata.');
    }
}
