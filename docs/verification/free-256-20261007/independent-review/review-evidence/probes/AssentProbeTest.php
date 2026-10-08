<?php

namespace Tests\ReviewProbes\Free256;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Independent review probe (not part of the suite). Question 2: literal, principal-bound assent and terms hash. */
final class AssentProbeTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
    }

    public function test_assent_is_literal_and_bound_to_the_exact_approved_terms_hash(): void
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        foreach (['true', 1, 'yes', null, false] as $value) {
            $this->refuses(fn () => $grants->accept($definition['id'], $this->assentInput($review, ['affirmed' => $value]), $owner['principal'], $owner['user']), 'assent_required');
        }
        $missing = $this->assentInput($review);
        unset($missing['affirmed']);
        $this->refuses(fn () => $grants->accept($definition['id'], $missing, $owner['principal'], $owner['user']), 'invalid_input');
        $this->refuses(fn () => $grants->accept($definition['id'], $this->assentInput($review, ['extra' => 1]), $owner['principal'], $owner['user']), 'invalid_input');
        $this->refuses(fn () => $grants->accept($definition['id'], $this->assentInput($review, ['termsHash' => hash('sha256', 'other terms')]), $owner['principal'], $owner['user']), 'stale_definition');
        $this->refuses(fn () => $grants->accept($definition['id'], $this->assentInput($review, ['declaredName' => 'Other Name']), $owner['principal'], $owner['user']), 'stale_display');
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $origin = $grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        $approved = hash('sha256', self::SYNTHETIC_TERMS);
        $row = (array) DB::table('production_free_origins')->first();
        $payload = json_decode(Crypt::decryptString($row['payload_ciphertext']), true);
        $this->assertSame($approved, $row['terms_hash']);
        $this->assertSame($approved, $payload['assent']['terms_hash']);
        $this->assertSame($approved, DB::table('production_free_definitions')->value('terms_hash'));
        $this->assertSame([$approved], config('production-free-grants.approved_terms_hashes'));
        $this->assertSame(true, $payload['assent']['affirmed']);
        $this->assertSame('unknown', $payload['marketing_consent']);
        $this->assertSame(CanonicalJson::hash($payload['assent']), $row['assent_hash']);
        $this->assertSame((int) $owner['user']->id, (int) $row['user_id']);
        $this->assertSame($owner['principal']->accountId, (int) $row['account_id']);
        $this->assertSame(CanonicalJson::encode($owner['principal']->durableBinding()), CanonicalJson::encode($payload['buyer_binding']));
        fwrite(STDERR, "PROBE assent.literal: 'true',1,'yes',null,false refused; missing/extra keys refused; foreign terms hash refused; origin terms_hash == definition terms_hash == configured approved hash ($approved); marketing_consent=unknown; origin {$origin['id']}\n");
    }

    public function test_replayed_and_cross_principal_assent(): void
    {
        $definition = $this->openDefinition();
        $a = $this->customer('a@example.test');
        $b = $this->customer('b@example.test');
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $a['principal'], $a['user']);
        $input = $this->assentInput($review);
        $first = $grants->accept($definition['id'], $input, $a['principal'], $a['user']);
        $this->assertSame($first, $grants->accept($definition['id'], $input, $a['principal'], $a['user']));
        // Principal/actor pairs that do not match are refused.
        $this->refuses(fn () => $grants->accept($definition['id'], $input, $a['principal'], $b['user']), 'identity_refused');
        $this->refuses(fn () => $grants->accept($definition['id'], $input, $b['principal'], $a['user']), 'identity_refused');
        // B replays A's exact assent body under B's own authenticated identity: the request key hash is account-keyed,
        // so this is a NEW origin for B (B's own literal assent), never a second grant for A or a grant A can use.
        $replayed = $grants->accept($definition['id'], $input, $b['principal'], $b['user']);
        $this->assertNotSame($first['id'], $replayed['id']);
        $rows = DB::table('production_free_origins')->orderBy('created_at')->get()->map(fn ($r) => (array) $r)->all();
        $this->assertCount(2, $rows);
        $this->assertSame([$a['principal']->accountId, $b['principal']->accountId], array_map(fn ($r) => (int) $r['account_id'], $rows));
        $library = new ProductionFreeGrantLibrary;
        $this->assertSame(1, $library->index($a['principal'], $a['user'])['total']);
        $this->assertSame(1, $library->index($b['principal'], $b['user'])['total']);
        $this->refuses(fn () => $library->show($first['id'], $b['principal'], $b['user']), 'not_found');
        // The display hash binds definition, availability, terms and declared name, not the principal: a client can
        // compute it without calling review(). The review step is not server-recorded.
        $c = $this->customer('c@example.test');
        $graphDisplay = $review['definition'];
        $computed = (new ProductionFreeGrants)->displayHash($graphDisplay, 'Client Computed Name');
        $direct = $grants->accept($definition['id'], [...$this->assentInput($review), 'declaredName' => 'Client Computed Name', 'displayHash' => $computed], $c['principal'], $c['user']);
        $this->assertSame('Client Computed Name', $direct['declaredName']);
        // Close, then a replay of an existing request key still returns the existing origin; a new key is refused.
        $definitions = new ProductionFreeGrantDefinitions;
        $staffRead = $definitions->read($definition['id'], $this->staff());
        $definitions->close($definition['id'], ['definitionHash' => $staffRead['definitionHash'], 'expectedOrdinal' => 1], $this->staff());
        $this->assertSame($first, $grants->accept($definition['id'], $input, $a['principal'], $a['user']));
        $d = $this->customer('d@example.test');
        $this->refuses(fn () => $grants->review($definition['id'], 'Late Buyer', $d['principal'], $d['user']), 'not_open');
        $this->refuses(fn () => $grants->accept($definition['id'], [...$input, 'requestKey' => (string) Str::uuid()], $d['principal'], $d['user']), 'not_open');
        // Reopen: the old availability id in a stale assent body is refused.
        $definitions->open($definition['id'], ['definitionHash' => $staffRead['definitionHash'], 'expectedOrdinal' => 2], $this->staff());
        $this->refuses(fn () => $grants->accept($definition['id'], [...$input, 'requestKey' => (string) Str::uuid()], $d['principal'], $d['user']), 'stale_definition');
        $this->assertSame(3, DB::table('production_free_origins')->count());
        // Removing the terms approval from configuration stops new assent but not delivery of an existing grant.
        (new ProductionFreeGrantDocuments)->render($first['id']);
        config(['production-free-grants.approved_terms_hashes' => []]);
        $e = $this->customer('e@example.test');
        $this->refuses(fn () => $grants->review($definition['id'], 'Another Buyer', $e['principal'], $e['user']), 'stale_terms');
        $seal = $library->show($first['id'], $a['principal'], $a['user'])['originSeal'];
        $authorization = (new ProductionFreeGrantDownloads)->authorize($first['id'], ['originSeal' => $seal, 'role' => 'contract'], $a['principal'], $a['user']);
        (new ProductionFreeGrantDownloads)->redeem($authorization['id'], $authorization['token'], $a['principal'], $a['user'])->close();
        fwrite(STDERR, "PROBE assent.replay: same-key replay idempotent (also after close); mismatched principal/actor refused; B replaying A's body creates B's own origin (2 origins, one per account); client-computed displayHash accepted without review(); not_open after close; stale availability refused after reopen; terms de-approval stops new assent but existing grant still delivers\n");
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
}
