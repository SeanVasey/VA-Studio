<?php

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Commerce\Models\Order;
use App\Support\CanonicalJson;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

// Read-only CLI evidence for a purchased offer in the existing private disposable harness.
// The native operator action performs every edit; this helper never changes catalog or commerce.
try {
    require_once __DIR__.'/unpaid-release-fixture.php';
    UnpaidReleaseBrowserFixture::check(PHP_SAPI === 'cli');
    $mode = $argv[1] ?? null;
    $project = $argv[2] ?? null;
    $phase = $argv[3] ?? null;
    UnpaidReleaseBrowserFixture::check(in_array($mode, ['prepare', 'verify'], true)
        && array_key_exists($project ?? '', UnpaidReleaseBrowserFixture::PROJECTS)
        && count($argv) === ($mode === 'prepare' ? 3 : 4)
        && ($mode === 'prepare' || in_array($phase, ['prepared', 'winner', 'recovered', 'uncertain'], true)));
    $directory = UnpaidReleaseBrowserFixture::directory();
    require __DIR__.'/../../vendor/autoload.php';
    $app = require __DIR__.'/../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    UnpaidReleaseBrowserFixture::effective($directory);
    $path = $directory.'/offer-draft-'.$project.'.json';
    UnpaidReleaseBrowserFixture::check(! is_link($path));
    if ($mode === 'prepare') {
        UnpaidReleaseBrowserFixture::check(! file_exists($path));
        $customers = UnpaidReleaseBrowserFixture::json($directory.'/customer-fixtures.json', 65536);
        $orderId = $customers['projects'][$project]['orderId'] ?? null;
        UnpaidReleaseBrowserFixture::check(is_string($orderId));
        $order = Order::where('public_id', $orderId)->sole();
        $line = DB::table('order_lines')->where('order_id', $order->id)->sole();
        $revision = OfferRevision::findOrFail($line->offer_revision_id);
        $offer = Offer::findOrFail($revision->offer_id);
        UnpaidReleaseBrowserFixture::check(DB::table('order_finalizations')->where('order_id', $order->id)
            ->where('outcome', 'paid')->where('mode', 'test')->count() === 1
            && $offer->current_revision_id === $revision->id
            && $offer->is_active && $offer->currency === 'USD' && $offer->price_minor > 0
            && $offer->price_minor <= 2147479647 && $offer->licenseVersion->status === 'published'
            && $offer->track_id === $revision->track_id && $offer->id <= 10);
        $prices = ['prepared' => $offer->price_minor, 'winner' => $offer->price_minor + 2000,
            'recovered' => $offer->price_minor + 3000, 'uncertain' => $offer->price_minor + 4000];
        $fixture = ['purpose' => 'offer-draft-native-v1', 'project' => $project,
            'marker' => getenv('VASEY_BROWSER_EXCEPTION_MARKER'), 'database' => $directory.'/database.sqlite',
            'operatorId' => 1, 'offerId' => $offer->id, 'revisionId' => $revision->id, 'trackId' => $offer->track_id,
            'title' => $offer->track->title, 'prices' => $prices, 'publishedPrice' => $revision->price_minor,
            'rows' => offerDraftRows(), 'guards' => offerDraftGuards()];
        UnpaidReleaseBrowserFixture::write($path, json_encode($fixture, JSON_THROW_ON_ERROR));
        echo json_encode(array_intersect_key($fixture, array_flip(['offerId', 'revisionId', 'trackId', 'title', 'prices', 'publishedPrice'])), JSON_THROW_ON_ERROR)."\n";
    } else {
        $fixture = UnpaidReleaseBrowserFixture::json($path, 8388608);
        UnpaidReleaseBrowserFixture::check(($fixture['purpose'] ?? null) === 'offer-draft-native-v1'
            && ($fixture['project'] ?? null) === $project && ($fixture['marker'] ?? null) === getenv('VASEY_BROWSER_EXCEPTION_MARKER')
            && ($fixture['database'] ?? null) === $directory.'/database.sqlite' && ($fixture['operatorId'] ?? null) === 1
            && is_int($fixture['offerId'] ?? null) && is_int($fixture['revisionId'] ?? null)
            && is_int($fixture['trackId'] ?? null) && is_array($fixture['prices'] ?? null)
            && array_keys($fixture['prices']) === ['prepared', 'winner', 'recovered', 'uncertain']
            && is_array($fixture['rows'] ?? null));
        $actual = offerDraftRows();
        $original = $fixture['rows'];
        $offerId = (string) $fixture['offerId'];
        $offer = Offer::findOrFail($fixture['offerId']);
        $expectedCount = ['prepared' => 0, 'winner' => 1, 'recovered' => 2, 'uncertain' => 3][$phase];
        UnpaidReleaseBrowserFixture::check($offer->current_revision_id === $fixture['revisionId']
            && $offer->track_id === $fixture['trackId'] && $offer->is_active && $offer->currency === 'USD'
            && $offer->price_minor === $fixture['prices'][$phase]);
        $previous = json_decode($original['offers'][$offerId], true, 64, JSON_THROW_ON_ERROR);
        $current = json_decode($actual['offers'][$offerId], true, 64, JSON_THROW_ON_ERROR);
        foreach ($previous as $field => $value) {
            if (in_array($field, ['price_minor', 'updated_at'], true)) {
                continue;
            }
            UnpaidReleaseBrowserFixture::check(($current[$field] ?? null) === $value);
        }
        $display = ['track_id' => $offer->track_id, 'license_version_id' => $offer->license_version_id,
            'price_minor' => $fixture['prices']['prepared'], 'currency' => $offer->currency,
            'deliverable_asset_ids' => $offer->deliverable_asset_ids];
        $added = array_diff_key($actual['audit_events'], $original['audit_events']);
        UnpaidReleaseBrowserFixture::check(count($added) === $expectedCount);
        uksort($added, fn ($left, $right) => (int) $left <=> (int) $right);
        $transitions = ['winner', 'recovered', 'uncertain'];
        $position = 0;
        foreach ($added as $id => $encoded) {
            $audit = json_decode($encoded, true, 64, JSON_THROW_ON_ERROR);
            $after = [...$display, 'price_minor' => $fixture['prices'][$transitions[$position++]]];
            UnpaidReleaseBrowserFixture::check($audit['action'] === 'catalog.offer.draft_saved'
                && $audit['subject_type'] === Offer::class && (int) $audit['subject_id'] === $offer->id
                && (int) $audit['actor_id'] === 1);
            $context = json_decode($audit['context'], true, 32, JSON_THROW_ON_ERROR);
            UnpaidReleaseBrowserFixture::check($context === ['schema_version' => 1,
                'current_revision_id' => $fixture['revisionId'], 'changed_fields' => ['price_minor'],
                'canonicalization_version' => CanonicalJson::VERSION,
                'before_hash' => CanonicalJson::hash($display), 'after_hash' => CanonicalJson::hash($after)]);
            $display = $after;
            unset($actual['audit_events'][$id]);
        }
        unset($actual['offers'][$offerId], $original['offers'][$offerId]);
        UnpaidReleaseBrowserFixture::check($actual === $original && offerDraftGuards() === $fixture['guards']);
        echo json_encode(['verified' => true, 'phase' => $phase, 'offerId' => $offer->id,
            'revisionId' => $offer->current_revision_id, 'price' => $offer->price_minor, 'updates' => $expectedCount,
            'originalsUnchanged' => true, 'guardsUnchanged' => true], JSON_THROW_ON_ERROR)."\n";
    }
} catch (Throwable) {
    // Never expose database contents, credentials, arbitrary paths or exception messages.
    fwrite(STDERR, "Isolated offer-draft evidence refused.\n");
    exit(1);
}

function offerDraftRows(): array
{
    $result = [];
    foreach (DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $table) {
        $result[$table->name] = [];
        foreach (DB::table($table->name)->get() as $record) {
            $encoded = json_encode((array) $record, JSON_THROW_ON_ERROR);
            $key = isset($record->id) ? (string) $record->id : hash('sha256', $encoded);
            $result[$table->name][$key] = $encoded;
        }
        ksort($result[$table->name]);
    }

    return $result;
}

function offerDraftGuards(): string
{
    return hash('sha256', json_encode(DB::select("SELECT type, name, tbl_name, sql FROM sqlite_master WHERE type IN ('trigger', 'index') ORDER BY type, name"), JSON_THROW_ON_ERROR));
}
