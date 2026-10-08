<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSchema;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * Independent review probe (not part of the suite). Question 1: authorization of definition approval/open/close/revoke,
 * forged resealed rows, and whether revocation denies later delivery and library reads.
 */
final class AuthorizationProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    public function test_reviewer_and_actor_matrix_with_panel_mfa_required(): void
    {
        $this->freeSetup(requireMfa: true);
        $definitions = new ProductionFreeGrantDefinitions;
        $author = $this->staff();
        $proposed = $definitions->propose($this->definitionInput(), $author);
        $approve = fn (User $who) => $definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $who);
        $this->refuses(fn () => $approve($author), 'self_review');
        $this->refuses(fn () => $approve($this->staff(mfa: false)), 'mfa_required');
        $this->refuses(fn () => $approve($this->staff(attributes: ['email_verified_at' => null])), 'staff_refused');
        $this->refuses(fn () => $approve($this->customer('probe-customer@example.test')['user']), 'staff_refused');
        $demoted = $this->staff();
        DB::table('users')->where('id', $demoted->id)->update(['is_admin' => false]);
        $this->refuses(fn () => $approve($demoted), 'staff_refused');
        $unsaved = new User(['is_admin' => true]);
        $this->refuses(fn () => $approve($unsaved), 'staff_refused');
        // Open before review, by anyone, is refused.
        $this->refuses(fn () => $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0], $author), 'not_reviewed');
        $this->assertSame(0, DB::table('production_free_reviews')->count());
        $reviewer = $this->staff();
        $approve($reviewer);
        // Terms not listed by configuration: open is refused even for the independent reviewer.
        config(['production-free-grants.approved_terms_hashes' => [str_repeat('0', 64)]]);
        $this->refuses(fn () => $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0], $reviewer), 'stale_terms');
        config(['production-free-grants.approved_terms_hashes' => [hash('sha256', self::SYNTHETIC_TERMS)]]);
        // Close/open by a staff member without MFA is refused.
        $this->refuses(fn () => $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0], $this->staff(mfa: false)), 'mfa_required');
        $opened = $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0], $author);
        $this->assertTrue($opened['open']);
        // The author may open/close after an independent approval: the independence lies in the review row only.
        $this->assertSame((int) $author->id, (int) DB::table('production_free_availability')->value('actor_user_id'));
        $this->refuses(fn () => $definitions->close($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 1], $this->customer('probe-c2@example.test')['user']), 'staff_refused');
        fwrite(STDERR, "PROBE auth.matrix: self_review, mfa_required, unverified, customer, demoted, unsaved, not_reviewed, stale_terms refused; author may open after independent approval\n");
    }

    public function test_panel_mfa_not_required_lets_a_non_mfa_admin_author_approve_and_open(): void
    {
        // AdminMultiFactor::satisfiedBy returns true when the admin panel does not require MFA, which the shipped
        // AdminPanelProvider does only in production; the 256 policy admits only local/testing.
        $this->freeSetup(requireMfa: false);
        $author = $this->staff(mfa: false);
        $reviewer = $this->staff(mfa: false);
        $opened = $this->openDefinition($author, $reviewer);
        $this->assertTrue($opened['open']);
        $this->assertNull(DB::table('users')->where('id', $reviewer->id)->value('app_authentication_secret'));
        fwrite(STDERR, "PROBE auth.mfa_not_required: non-MFA author+reviewer proposed/approved/opened (open={$opened['open']})\n");
    }

    public function test_forged_resealed_rows_and_tamper(): void
    {
        $this->freeSetup();
        $definitions = new ProductionFreeGrantDefinitions;
        $author = $this->staff();
        $proposed = $definitions->propose($this->definitionInput(), $author);
        $definition = (array) DB::table('production_free_definitions')->first();
        $reviewPayload = fn (string $id, int $reviewer): array => ['schema_version' => 'production-free-review-v1', 'review_id' => $id,
            'definition_id' => $definition['id'], 'definition_hash' => $definition['definition_hash'], 'terms_hash' => $definition['terms_hash'],
            'decision' => 'approved', 'reviewer_user_id' => $reviewer, 'source_proof' => str_repeat('b', 64), 'reviewed_at' => '2026-10-08T00:00:00Z'];
        $reviewRow = fn (string $id, int $reviewer): array => ['id' => $id, 'definition_id' => $definition['id'], 'definition_hash' => $definition['definition_hash'],
            'terms_hash' => $definition['terms_hash'], 'reviewer_user_id' => $reviewer, 'decision' => 'approved', 'provenance' => 'synthetic_rehearsal',
            'created_at' => now('UTC')->format('Y-m-d H:i:s')];
        // (a) A key holder forging a self-review is refused by the insert guard.
        $id = (string) Str::uuid();
        $this->pdoRefuses(fn () => $this->forge('production_free_reviews', $reviewRow($id, (int) $author->id), $reviewPayload($id, (int) $author->id)));
        // (b) A forged review naming a different admin WITHOUT MFA is admitted by the guard (MFA is invisible to SQL)
        // and then accepted by the verified graph: approval forgery needs APP_KEY plus direct INSERT on the database.
        $noMfa = $this->staff(mfa: false);
        $id = (string) Str::uuid();
        $this->forge('production_free_reviews', $reviewRow($id, (int) $noMfa->id), $reviewPayload($id, (int) $noMfa->id));
        $read = $definitions->read($proposed['id'], $author);
        $this->assertTrue($read['reviewed']);
        $this->assertSame((int) $noMfa->id, $read['reviewerUserId']);
        // (c) A forged 'open' availability for terms Sean never listed is admitted by SQL, but customer review/assent
        // re-check the configured approved terms and refuse.
        config(['production-free-grants.approved_terms_hashes' => []]);
        $aid = (string) Str::uuid();
        $this->forge('production_free_availability', ['id' => $aid, 'definition_id' => $definition['id'], 'review_id' => $id, 'ordinal' => 0,
            'kind' => 'open', 'actor_user_id' => (int) $author->id, 'created_at' => now('UTC')->format('Y-m-d H:i:s')],
            ['schema_version' => 'production-free-availability-v1', 'availability_id' => $aid, 'definition_id' => $definition['id'],
                'definition_hash' => $definition['definition_hash'], 'kind' => 'open', 'ordinal' => 0, 'actor_user_id' => (int) $author->id, 'at' => '2026-10-08T00:00:00Z']);
        $owner = $this->customer();
        $this->refuses(fn () => (new ProductionFreeGrants)->review($proposed['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']), 'stale_terms');
        // (d) Without APP_KEY a forged row fails the seal on read.
        $id2 = (string) Str::uuid();
        $definition2 = $definitions->propose($this->definitionInput(['title' => 'Second synthetic definition']), $author);
        $row = (array) DB::table('production_free_definitions')->where('id', $definition2['id'])->first();
        $values = ['id' => $id2, 'definition_id' => $row['id'], 'definition_hash' => $row['definition_hash'], 'terms_hash' => $row['terms_hash'],
            'reviewer_user_id' => (int) $noMfa->id, 'decision' => 'approved', 'provenance' => 'synthetic_rehearsal', 'created_at' => now('UTC')->format('Y-m-d H:i:s')];
        $values['payload_ciphertext'] = ProductionFreeGrantRecords::encrypt(['schema_version' => 'production-free-review-v1']);
        $values['seal'] = hash_hmac('sha256', 'guess', 'not-the-app-key');
        $this->rawInsert('production_free_reviews', $values);
        $this->refuses(fn () => $definitions->read($definition2['id'], $author), 'tampered');
        // (e) UPDATE/DELETE of approval, availability and revocation evidence is refused natively by the guards.
        foreach (['production_free_reviews', 'production_free_availability', 'production_free_definitions'] as $table) {
            $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('UPDATE '.$table." SET created_at = created_at"));
            $this->pdoRefuses(fn () => DB::connection()->getPdo()->exec('DELETE FROM '.$table));
        }
        // (f) A DBA bypass (dropped guard + column edit) is caught by the seal on the next read.
        $pdo = DB::connection()->getPdo();
        $guard = $pdo->query("SELECT sql FROM sqlite_master WHERE name = 'production_free_definitions_update'")->fetchColumn();
        $pdo->exec('DROP TRIGGER production_free_definitions_update');
        $pdo->exec("UPDATE production_free_definitions SET max_origins = 999 WHERE id = '".$proposed['id']."'");
        $this->refuses(fn () => $definitions->read($proposed['id'], $author), 'schema_prefix');
        $pdo->exec($guard);
        $this->refuses(fn () => $definitions->read($proposed['id'], $author), 'tampered');
        fwrite(STDERR, "PROBE auth.forgery: forged self-review refused by guard; forged non-MFA review admitted+read (needs APP_KEY+INSERT); forged open w/o approved terms refused at customer review (stale_terms); unkeyed seal refused (tampered); update/delete refused; DBA column edit refused (tampered)\n");
    }

    public function test_revocation_denies_delivery_and_rendering_but_library_still_lists_the_revoked_original(): void
    {
        $this->freeSetup();
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $library = new ProductionFreeGrantLibrary;
        $seal = $library->show($origin['id'], $owner['principal'], $owner['user'])['originSeal'];
        $downloads = new ProductionFreeGrantDownloads;
        $outstanding = [];
        foreach (ProductionFreeGrantDownloads::ROLES as $role) {
            $outstanding[$role] = $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $owner['principal'], $owner['user']);
        }
        $this->refuses(fn () => $grants->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'probe'], $owner['user']), 'staff_refused');
        $this->refuses(fn () => $grants->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'probe'], $this->staff(mfa: false)), 'mfa_required');
        $this->refuses(fn () => $grants->revoke($origin['id'], ['originSeal' => str_repeat('c', 64), 'reason' => 'probe'], $this->staff()), 'stale_origin');
        $grants->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'Synthetic probe revocation.'], $this->staff());
        foreach ($outstanding as $role => $authorization) {
            $this->refuses(fn () => $downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']), 'revoked');
            $this->refuses(fn () => $downloads->authorize($origin['id'], ['originSeal' => $seal, 'role' => $role], $owner['principal'], $owner['user']), 'revoked');
        }
        $this->assertSame(0, DB::table('production_free_redemptions')->count());
        $this->refuses(fn () => $grants->revoke($origin['id'], ['originSeal' => $seal, 'reason' => 'again'], $this->staff()), 'already_revoked');
        // A forged authorization row for the revoked origin is refused by the insert guard.
        $auth = (array) DB::table('production_free_authorizations')->first();
        $this->pdoRefuses(fn () => $this->rawInsert('production_free_authorizations', [...$auth, 'id' => (string) Str::uuid(), 'token_hash' => hash('sha256', 'forged')]));
        $shown = $library->show($origin['id'], $owner['principal'], $owner['user']);
        $index = $library->index($owner['principal'], $owner['user']);
        $this->assertTrue($shown['revoked']);
        $this->assertFalse($shown['deliverable']);
        $this->assertCount(4, $shown['artifacts']);
        $this->assertSame(1, $index['total']);
        fwrite(STDERR, 'PROBE auth.revocation: 4 outstanding authorizations refused (revoked), new authorizations refused, 0 redemptions; library show/index still list the origin revoked='
            .json_encode($shown['revoked']).' deliverable='.json_encode($shown['deliverable']).' artifacts='.count($shown['artifacts'])."\n");
    }

    private function forge(string $table, array $values, array $payload): void
    {
        $values['payload_ciphertext'] = ProductionFreeGrantRecords::encrypt($payload);
        $values['seal'] = ProductionFreeGrantRecords::seal($table, $values);
        $this->rawInsert($table, $values);
    }

    private function rawInsert(string $table, array $row): void
    {
        $this->assertContains($table, ProductionFreeGrantSchema::TABLES);
        $statement = DB::connection()->getPdo()->prepare('INSERT INTO '.$table.' ('.implode(', ', array_keys($row)).') VALUES ('.implode(', ', array_fill(0, count($row), '?')).')');
        $statement->execute(array_values($row));
    }

    private function refuses(callable $operation, string $reason): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason);
        }
    }

    private function pdoRefuses(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Guard must refuse');
        } catch (PDOException) {
            $this->assertTrue(true);
        }
    }
}
