<?php

namespace Tests\Feature\Migration;

use App\Domain\Catalog\Models\Track;
use App\Domain\Migration\History\LegacyArchiveRefusal;
use App\Domain\Migration\History\LegacyGrantReview;
use App\Domain\Migration\History\LegacyHistoryArchive;
use App\Domain\Migration\History\LegacyTrackLinks;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Support\LegacyHistoryFixtures;
use Tests\Support\LicenseFixtures;
use Tests\TestCase;

/**
 * Append-only links from archived legacy lines to store tracks through catalog import mappings: linked, unlinked
 * and ambiguous per line, idempotent restart, staff resolution, and nothing written outside the archive.
 * Synthetic `families` package: five track lines and four kit, service and membership lines.
 */
class LegacyTrackLinksTest extends TestCase
{
    use RefreshDatabase;

    private const SYSTEM = 'synthetic/beatstars-history';

    private const OTHER = 'synthetic/other-catalog';

    private LegacyTrackLinks $links;

    private User $operator;

    private array $snapshot;

    private string $sha;

    /** @var array<string, string> "order/line" => line record key */
    private array $lines = [];

    /** @var array<string, array{mapping_id: int, track_id: int}> */
    private array $mappings = [];

    /** @var array<string, string> */
    private array $contracts = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePrivateMediaStorage();
        $this->links = new LegacyTrackLinks;
        $this->operator = LicenseFixtures::admin();
        // track-01 and track-02: the catalog importer's own identity. track-05: two candidates. track-06: only
        // another source system's record carries the id. track-09: nothing.
        foreach (['01' => self::SYSTEM, '02' => self::SYSTEM, '05' => self::SYSTEM, '05-other' => self::OTHER, '06-other' => self::OTHER] as $name => $system) {
            $this->mappings[$name] = LegacyHistoryFixtures::catalogMapping($system, 'synthetic-track-'.substr($name, 0, 2), $this->operator);
        }
        $loaded = (new LegacyHistoryArchive)->load(LegacyHistoryFixtures::privateCopy('families'));
        $this->snapshot = $loaded['result']['snapshot'];
        $this->sha = $loaded['result']['snapshot_sha256'];
        foreach ($this->snapshot['lines'] as $line) {
            $this->lines[$line['source_order_id'].'/'.$line['source_line_id']] = $line['record_key'];
        }
        $this->contracts = $loaded['source']['contracts'];
    }

    protected function tearDown(): void
    {
        LegacyHistoryFixtures::cleanup();
        parent::tearDown();
    }

    public function test_links_follow_a_complete_archive_and_reconciliation_counts_every_state(): void
    {
        $report = $this->links->report($this->snapshot, $this->sha);
        $this->assertSame(['track' => 5, 'non_track' => 4, 'not_archived' => 5], $report['lines']);
        $this->assertSame(['linked' => 0, 'unlinked' => 4, 'ambiguous' => 1], $report['states']);
        $this->assertSame(0, $report['plan']['create'], 'A line that is not archived cannot be linked.');
        $this->refused('import_incomplete', fn () => LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, 1000));
        LegacyHistoryFixtures::import($this->snapshot, $this->sha, $this->contracts, $this->operator, 5);
        $this->refused('import_incomplete', fn () => LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, 1000));
        LegacyHistoryFixtures::import($this->snapshot, $this->sha, $this->contracts, $this->operator, 1000);
        $this->assertSame(0, DB::table('legacy_history_track_links')->count());

        $report = $this->links->report($this->snapshot, $this->sha);
        $this->assertSame(['track' => 5, 'non_track' => 4, 'not_archived' => 0], $report['lines']);
        $this->assertSame(['linked' => 0, 'unlinked' => 4, 'ambiguous' => 1], $report['states']);
        $this->assertSame(['no_candidate' => 1, 'other_source_system_candidate' => 1, 'exact_candidate_pending' => 2], $report['unlinked']);
        $this->assertSame(2, $report['plan']['create']);
        $this->assertSame(['ambiguous', 'unlinked', 'unlinked', 'unlinked', 'unlinked'], $this->sorted(array_values($report['line_states'])));
        $this->assertSame('ambiguous', $report['line_states'][$this->lines['synthetic-ord-2004/1']]);
        // Only the lines that need a person list their candidates; ids only.
        $this->assertEqualsCanonicalizing([$this->lines['synthetic-ord-2004/1'], $this->lines['synthetic-ord-2008/1']], array_keys($report['needs_resolution']));
        $this->assertEqualsCanonicalizing([$this->mappings['05'] + ['same_source_system' => true], $this->mappings['05-other'] + ['same_source_system' => false]],
            $report['needs_resolution'][$this->lines['synthetic-ord-2004/1']]);
        // The kit that shares its raw id with track-01 is not a track line and appears in no state.
        $this->assertArrayNotHasKey($this->lines['synthetic-ord-2001/2'], $report['line_states']);
    }

    public function test_segmented_linking_is_idempotent_restartable_and_writes_nothing_outside_the_archive(): void
    {
        LegacyHistoryFixtures::import($this->snapshot, $this->sha, $this->contracts, $this->operator, 1000);
        $before = LegacyHistoryFixtures::nonArchiveState();

        $first = LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, 1);
        $this->assertSame([1, 1, false], [$first['processed'], $first['pending'], $first['complete']]);
        // An interrupted segment rolls back whole, including its audit event.
        try {
            LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, 1000, function (): void {
                throw new RuntimeException('Synthetic interruption');
            });
            $this->fail('The interruption was not raised.');
        } catch (RuntimeException $error) {
            $this->assertSame('Synthetic interruption', $error->getMessage());
        }
        $this->assertSame(1, DB::table('legacy_history_track_links')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', LegacyTrackLinks::SEGMENT_ACTION)->count());
        $resumed = LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, 1000);
        $this->assertSame([1, 0, true], [$resumed['processed'], $resumed['pending'], $resumed['complete']]);
        $this->assertSame(['linked' => 2, 'unlinked' => 2, 'ambiguous' => 1], $resumed['states']);

        // Re-running the import batch and the link segment creates nothing: no second batch, record or link.
        $rows = DB::table('legacy_history_track_links')->orderBy('id')->get()->toJson();
        $again = LegacyHistoryFixtures::import($this->snapshot, $this->sha, $this->contracts, $this->operator, 1000);
        $this->assertSame([0, 0, true], [$again['processed'], $again['pending'], $again['complete']]);
        foreach ([1, 1000] as $limit) {
            $rerun = LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, $limit);
            $this->assertSame([0, 0, true], [$rerun['processed'], $rerun['pending'], $rerun['complete']]);
        }
        $this->assertSame($rows, DB::table('legacy_history_track_links')->orderBy('id')->get()->toJson());
        $this->assertSame(1, DB::table('legacy_history_batches')->count());
        $this->assertSame(9, DB::table('legacy_history_lines')->count());

        $linked = DB::table('legacy_history_track_links')->orderBy('track_id')->get();
        $this->assertSame([$this->mappings['01']['track_id'], $this->mappings['02']['track_id']], $linked->pluck('track_id')->map(fn ($id) => (int) $id)->all());
        $this->assertSame([LegacyTrackLinks::EXACT], $linked->pluck('method')->unique()->values()->all());
        $this->assertSame([null], $linked->pluck('reference_hmac')->unique()->values()->all());
        $report = $this->links->report($this->snapshot, $this->sha);
        $this->assertSame(['exact_source_identity' => 2, 'staff_resolution' => 0], $report['linked_by']);
        $this->assertSame(['no_candidate' => 1, 'other_source_system_candidate' => 1, 'exact_candidate_pending' => 0], $report['unlinked']);
        $this->assertSame(0, $report['plan']['create']);

        // Archive-only: no order, grant, entitlement, customer account, consent or download row anywhere.
        $this->assertSame($before, LegacyHistoryFixtures::nonArchiveState());
        $this->assertSame(['archive_only'], DB::table('legacy_history_lines')->distinct()->pluck('classification')->all());
        $this->assertSame(['unknown'], DB::table('legacy_history_customers')->distinct()->pluck('marketing_consent')->all());
        $this->assertSame('reconciled', (new LegacyHistoryArchive)->reconcile($this->snapshot, $this->sha)['result']);

        // A second candidate that appears later does not unlink an exact identity link, and guesses nothing new.
        LegacyHistoryFixtures::catalogMapping(self::OTHER, 'synthetic-track-01', $this->operator);
        $this->assertSame(['linked' => 2, 'unlinked' => 2, 'ambiguous' => 1], $this->links->report($this->snapshot, $this->sha)['states']);
    }

    public function test_an_ambiguous_line_stays_ambiguous_until_staff_resolve_it_with_an_audited_choice(): void
    {
        LegacyHistoryFixtures::import($this->snapshot, $this->sha, $this->contracts, $this->operator, 1000);
        LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, 1000);
        $ambiguous = $this->lines['synthetic-ord-2004/1'];
        $this->assertNull(DB::table('legacy_history_track_links')->where('line_record_key', $ambiguous)->first());
        $inspected = $this->links->inspect($ambiguous);
        $this->assertSame(['ambiguous', null, 2, null], [$inspected['state'], $inspected['family'], count($inspected['candidates']), $inspected['linked_mapping_id']]);
        $before = LegacyHistoryFixtures::nonArchiveState();
        $reference = 'SYNTHETIC resolution evidence pointer';

        // Only one of the line's own candidates can be chosen; nothing is written by a refusal.
        $this->refused('candidate_refused', fn () => LegacyHistoryFixtures::resolveTrackLink($ambiguous, $this->mappings['01']['mapping_id'], $this->operator, $reference));
        $this->refused('candidate_refused', fn () => LegacyHistoryFixtures::resolveTrackLink($ambiguous, 999999, $this->operator, $reference));
        $this->refused('no_candidate', fn () => LegacyHistoryFixtures::resolveTrackLink($this->lines['synthetic-ord-2003/1'], $this->mappings['01']['mapping_id'], $this->operator, $reference));
        $this->refused('not_a_track_line', fn () => LegacyHistoryFixtures::resolveTrackLink($this->lines['synthetic-ord-2001/2'], $this->mappings['01']['mapping_id'], $this->operator, $reference));
        $this->refused('already_linked', fn () => LegacyHistoryFixtures::resolveTrackLink($this->lines['synthetic-ord-2001/1'], $this->mappings['05']['mapping_id'], $this->operator, $reference));
        $this->refused('line_not_archived', fn () => LegacyHistoryFixtures::resolveTrackLink(str_repeat('a', 64), 1, $this->operator, $reference));
        $this->refused('staff_authority_refused', fn () => $this->links->resolve($ambiguous, $this->mappings['05']['mapping_id'], $this->operator, $reference,
            LegacyHistoryFixtures::attestation($this->operator, LegacyTrackLinks::RESOLVE_ATTESTATION_ACTION,
                LegacyTrackLinks::resolveScope($ambiguous, $this->mappings['05-other']['mapping_id']), $reference)));
        $this->assertSame(2, DB::table('legacy_history_track_links')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', LegacyTrackLinks::RESOLVE_ACTION)->count());

        $resolved = LegacyHistoryFixtures::resolveTrackLink($ambiguous, $this->mappings['05']['mapping_id'], $this->operator, $reference);
        $this->assertSame(['recorded', $this->mappings['05']['track_id']], [$resolved['outcome'], $resolved['track_id']]);
        $row = DB::table('legacy_history_track_links')->where('line_record_key', $ambiguous)->sole();
        $this->assertSame([LegacyTrackLinks::STAFF, $this->mappings['05']['mapping_id'], $this->operator->id], [$row->method, (int) $row->mapping_id, (int) $row->actor_id]);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', $row->reference_hmac);
        $audit = DB::table('audit_events')->where('action', LegacyTrackLinks::RESOLVE_ACTION)->sole();
        $this->assertSame([(int) $row->id, $this->operator->id], [(int) $audit->subject_id, (int) $audit->actor_id]);
        $context = json_decode($audit->context, true);
        $this->assertSame([$ambiguous, $this->mappings['05']['mapping_id'], 2, false, $row->candidates_sha256],
            [$context['line_record_key'], $context['mapping_id'], $context['candidates'], $context['entitlement_created'], $context['candidates_sha256']]);
        $this->assertStringNotContainsString('SYNTHETIC resolution', json_encode($row).$audit->context);

        // The decision is append-only: the same choice is a no-op, another choice is refused.
        $this->assertSame('unchanged', LegacyHistoryFixtures::resolveTrackLink($ambiguous, $this->mappings['05']['mapping_id'], $this->operator, $reference)['outcome']);
        $this->refused('already_linked', fn () => LegacyHistoryFixtures::resolveTrackLink($ambiguous, $this->mappings['05-other']['mapping_id'], $this->operator, $reference));
        // A single candidate from another source system proves no identity; it too needs a named staff choice.
        $other = $this->lines['synthetic-ord-2008/1'];
        $this->assertSame('recorded', LegacyHistoryFixtures::resolveTrackLink($other, $this->mappings['06-other']['mapping_id'], $this->operator, $reference)['outcome']);

        $report = $this->links->report($this->snapshot, $this->sha);
        $this->assertSame(['linked' => 4, 'unlinked' => 1, 'ambiguous' => 0], $report['states']);
        $this->assertSame(['exact_source_identity' => 2, 'staff_resolution' => 2], $report['linked_by']);
        $this->assertSame([], $report['needs_resolution']);
        $this->assertSame(1, DB::table('audit_events')->where('action', LegacyTrackLinks::RESOLVE_ACTION)->where('subject_id', $row->id)->count());
        $this->assertSame($before, LegacyHistoryFixtures::nonArchiveState());
    }

    public function test_the_unambiguous_case_is_not_a_staff_choice_and_unreadable_catalog_evidence_refuses_every_read(): void
    {
        LegacyHistoryFixtures::import($this->snapshot, $this->sha, $this->contracts, $this->operator, 1000);
        $this->refused('resolution_not_required', fn () => LegacyHistoryFixtures::resolveTrackLink($this->lines['synthetic-ord-2001/1'],
            $this->mappings['01']['mapping_id'], $this->operator, 'SYNTHETIC reference'));

        // A mapping whose evidence does not prove its record key could hide a candidate: nothing is reported or linked.
        $genuine = LegacyHistoryFixtures::catalogMapping(self::SYSTEM, 'synthetic-track-77', $this->operator);
        $hash = hash('sha256', 'forged');
        DB::table('catalog_import_mappings')->insert(['batch_id' => DB::table('catalog_import_batches')->value('id'), 'record_key' => $hash,
            'source_record_sha256' => $hash, 'track_id' => Track::create(['title' => 'SYNTHETIC forged', 'slug' => 'synthetic-forged', 'artist' => 'Test only'])->id,
            'track_sha256' => $hash, 'actor_id' => $this->operator->id,
            'evidence_ciphertext' => DB::table('catalog_import_mappings')->where('id', $genuine['mapping_id'])->value('evidence_ciphertext'), 'created_at' => now()]);
        $this->refused('catalog_mapping_integrity', fn () => $this->links->report($this->snapshot, $this->sha));
        $this->refused('catalog_mapping_integrity', fn () => LegacyHistoryFixtures::linkTracks($this->snapshot, $this->sha, $this->operator, 1000));
        $this->refused('catalog_mapping_integrity', fn () => $this->links->inspect($this->lines['synthetic-ord-2004/1']));
        $this->assertSame(0, DB::table('legacy_history_track_links')->count());
    }

    public function test_a_family_line_is_archived_like_any_line_but_is_never_reviewable_as_a_grant(): void
    {
        LegacyHistoryFixtures::import($this->snapshot, $this->sha, $this->contracts, $this->operator, 1000);
        $this->assertSame('reconciled', (new LegacyHistoryArchive)->reconcile($this->snapshot, $this->sha)['result']);
        $stored = DB::table('legacy_history_lines')->pluck('track_source_id')->all();
        $this->assertEqualsCanonicalizing(['synthetic-track-01', 'synthetic-track-02', 'synthetic-track-05', 'synthetic-track-06', 'synthetic-track-09',
            'kit:synthetic-track-01', 'service:synthetic-service-01', 'membership:synthetic-plan-01', 'membership:synthetic-plan-01'], $stored);
        $review = new LegacyGrantReview;
        foreach (['synthetic-ord-2001/2', 'synthetic-ord-2005/1', 'synthetic-ord-2006/1', 'synthetic-ord-2007/1'] as $family) {
            $this->assertContains('product_family_not_reviewable', $review->inspect($this->lines[$family])['blockers'], $family);
            $this->assertSame('archive_only', $review->classification($this->lines[$family]));
        }
        $this->assertNotContains('product_family_not_reviewable', $review->inspect($this->lines['synthetic-ord-2001/1'])['blockers']);
        $this->assertSame(0, DB::table('legacy_history_grant_reviews')->count());
    }

    private function refused(string $reason, callable $write): void
    {
        try {
            $write();
            $this->fail($reason.' accepted');
        } catch (LegacyArchiveRefusal $refusal) {
            $this->assertSame($reason, $refusal->reason);
        }
    }

    private function sorted(array $values): array
    {
        sort($values, SORT_STRING);

        return $values;
    }
}
