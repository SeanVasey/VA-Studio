<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Typed customer review and literal assent. Nothing is inferred; every refusal leaves zero origins. */
final class ProductionFreeGrantAssentTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private ProductionFreeGrants $grants;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->grants = new ProductionFreeGrants;
    }

    public function test_assent_records_exact_display_buyer_binding_and_unknown_marketing_consent(): void
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();
        $review = $this->grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        $this->assertSame(self::SYNTHETIC_TERMS, $review['definition']['terms_text']);
        $this->assertArrayNotHasKey('source', $review['definition']);
        $origin = $this->grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']);
        $row = (array) DB::table('production_free_origins')->first();
        $payload = json_decode(Crypt::decryptString($row['payload_ciphertext']), true);
        $this->assertSame(CanonicalJson::encode($owner['binding']), CanonicalJson::encode($payload['buyer_binding']));
        $this->assertSame($owner['binding']['origin_id'], $row['identity_origin_id']);
        $this->assertSame(CanonicalJson::hash($owner['binding']), $row['owner_binding_hash']);
        $this->assertSame($review['displayHash'], $payload['assent']['display_hash']);
        $this->assertTrue($payload['assent']['affirmed']);
        $this->assertSame('unknown', $payload['marketing_consent']);
        $this->assertSame('production-free-license-grant-v1', $row['purpose']);
        $this->assertSame('Declared Synthetic Buyer', $origin['declaredName']);
        $this->assertSame(0, $origin['amountMinor']);
    }

    public function test_only_literal_true_is_affirmative_assent(): void
    {
        [$definition, $owner, $review] = $this->reviewed();
        foreach ([false, 'true', 1, null, 'yes'] as $value) {
            $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review, ['affirmed' => $value]), $owner['principal'], $owner['user']), 'assent_required');
        }
        $input = $this->assentInput($review);
        unset($input['affirmed']);
        $this->refuses(fn () => $this->grants->accept($definition['id'], $input, $owner['principal'], $owner['user']), 'invalid_input');
        $this->assertSame(0, DB::table('production_free_origins')->count());
    }

    public function test_stale_or_forged_display_terms_definition_and_availability_are_refused(): void
    {
        [$definition, $owner, $review] = $this->reviewed();
        $cases = [
            'stale_definition' => [['definitionHash' => str_repeat('0', 64)], ['termsHash' => hash('sha256', 'other terms')],
                ['availabilityId' => '00000000-0000-4000-8000-000000000000']],
            'stale_display' => [['declaredName' => 'Another Declared Name'], ['displayHash' => str_repeat('1', 64)]],
        ];
        foreach ($cases as $reason => $overrides) {
            foreach ($overrides as $override) {
                $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review, $override), $owner['principal'], $owner['user']), $reason);
            }
        }
        $this->assertSame(0, DB::table('production_free_origins')->count());
    }

    public function test_closed_unreviewed_unapproved_terms_and_withdrawn_sources_refuse_review_and_assent(): void
    {
        $author = $this->staff();
        $reviewer = $this->staff();
        $definitions = new ProductionFreeGrantDefinitions;
        $proposed = $definitions->propose($this->definitionInput(), $author);
        $owner = $this->customer();
        $this->refuses(fn () => $this->grants->review($proposed['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']), 'not_open');
        $definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer);
        $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0], $reviewer);
        $review = $this->grants->review($proposed['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);
        config(['production-free-grants.approved_terms_hashes' => []]);
        $this->refuses(fn () => $this->grants->accept($proposed['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'stale_terms');
        config(['production-free-grants.approved_terms_hashes' => [hash('sha256', self::SYNTHETIC_TERMS)]]);
        $this->sources->ready = false;
        $this->refuses(fn () => $this->grants->accept($proposed['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'source_not_ready');
        $this->sources->ready = true;
        $definitions->close($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 1], $reviewer);
        $this->refuses(fn () => $this->grants->accept($proposed['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'not_open');
        // Reopening makes a new availability event; assent shown for the earlier event is stale, not silently carried.
        $definitions->open($proposed['id'], ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 2], $reviewer);
        $this->refuses(fn () => $this->grants->accept($proposed['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'stale_definition');
        $this->assertSame(0, DB::table('production_free_origins')->count());
    }

    public function test_request_key_replay_is_idempotent_and_reuse_or_second_grant_is_refused(): void
    {
        [$definition, $owner, $review] = $this->reviewed();
        $input = $this->assentInput($review);
        $first = $this->grants->accept($definition['id'], $input, $owner['principal'], $owner['user']);
        $this->assertSame($first, $this->grants->accept($definition['id'], $input, $owner['principal'], $owner['user']));
        $this->refuses(fn () => $this->grants->accept($definition['id'], [...$input, 'declaredName' => 'Changed Name'], $owner['principal'], $owner['user']), 'request_key_reused');
        $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'already_granted');
        $this->assertSame(1, DB::table('production_free_origins')->count());
    }

    public function test_definition_cap_is_enforced_and_closing_preserves_earlier_originals(): void
    {
        $definition = $this->openDefinition(overrides: ['maxOrigins' => 1]);
        $first = $this->customer('first@example.test');
        $review = $this->grants->review($definition['id'], 'First Synthetic Buyer', $first['principal'], $first['user']);
        $this->grants->accept($definition['id'], $this->assentInput($review), $first['principal'], $first['user']);
        $second = $this->customer('second@example.test');
        $review = $this->grants->review($definition['id'], 'Second Synthetic Buyer', $second['principal'], $second['user']);
        $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review), $second['principal'], $second['user']), 'cap_reached');
        $this->assertSame(1, DB::table('production_free_origins')->count());
    }

    public function test_foreign_principal_actor_pairs_and_staff_actors_are_refused(): void
    {
        [$definition, $owner, $review] = $this->reviewed();
        $other = $this->customer('other@example.test');
        $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $other['user']), 'identity_refused');
        $this->refuses(fn () => $this->grants->review($definition['id'], 'Declared Synthetic Buyer', $other['principal'], $owner['user']), 'identity_refused');
        $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $this->staff()), 'identity_refused');
        DB::table('users')->where('id', $owner['user']->id)->update(['password' => bcrypt('Rotated password 123')]);
        $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'identity_refused');
        $this->assertSame(0, DB::table('production_free_origins')->count());
    }

    public function test_disabled_policy_refuses_customer_review_and_assent(): void
    {
        [$definition, $owner, $review] = $this->reviewed();
        config(['production-free-grants.enabled' => false]);
        $this->refuses(fn () => $this->grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user']), 'disabled');
        $this->refuses(fn () => $this->grants->accept($definition['id'], $this->assentInput($review), $owner['principal'], $owner['user']), 'disabled');
    }

    private function reviewed(): array
    {
        $definition = $this->openDefinition();
        $owner = $this->customer();

        return [$definition, $owner, $this->grants->review($definition['id'], 'Declared Synthetic Buyer', $owner['principal'], $owner['user'])];
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
