<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantSources;
use Illuminate\Support\Facades\DB;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/** Author/reviewer/opener policy matrix. Every refusal leaves the append-only evidence exactly as it was. */
final class ProductionFreeGrantApprovalTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private ProductionFreeGrantDefinitions $definitions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->definitions = new ProductionFreeGrantDefinitions;
    }

    public function test_independent_review_then_open_close_reopen_is_an_append_only_chain(): void
    {
        $author = $this->staff();
        $reviewer = $this->staff();
        $proposed = $this->definitions->propose($this->definitionInput(), $author);
        $this->assertFalse($proposed['reviewed']);
        $this->assertSame($author->id, $proposed['authorUserId']);
        $approved = $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer);
        $this->assertSame($reviewer->id, $approved['reviewerUserId']);
        $hash = $proposed['definitionHash'];
        $this->assertTrue($this->definitions->open($proposed['id'], ['definitionHash' => $hash, 'expectedOrdinal' => 0], $reviewer)['open']);
        $this->assertFalse($this->definitions->close($proposed['id'], ['definitionHash' => $hash, 'expectedOrdinal' => 1], $author)['open']);
        $reopened = $this->definitions->open($proposed['id'], ['definitionHash' => $hash, 'expectedOrdinal' => 2], $author);
        $this->assertTrue($reopened['open']);
        $this->assertSame(2, $reopened['availabilityOrdinal']);
        $this->assertSame(['open', 'closed', 'open'], DB::table('production_free_availability')->orderBy('ordinal')->pluck('kind')->all());
        $this->assertSame(['synthetic_rehearsal'], DB::table('production_free_definitions')->pluck('provenance')->all());
    }

    public function test_source_ids_and_hashes_are_preserved_verbatim_in_the_sealed_definition(): void
    {
        $proposed = $this->definitions->propose($this->definitionInput(), $this->staff());
        $this->assertSame(['license_hash' => str_repeat('a', 64), 'license_id' => 'synthetic-license-1',
            'rights_reference' => 'synthetic-rights-1', 'track_id' => 'synthetic-track-1'], $proposed['source']);
        $this->assertSame(['master_wav', 'download_mp3', 'stems_zip'], array_column($proposed['assets'], 'role'));
    }

    public function test_reviewer_must_differ_from_author(): void
    {
        $author = $this->staff();
        $proposed = $this->definitions->propose($this->definitionInput(), $author);
        $this->refuses(fn () => $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $author), 'self_review');
        $this->assertSame(0, DB::table('production_free_reviews')->count());
    }

    public function test_reviewer_must_approve_the_exact_current_hash_once(): void
    {
        $proposed = $this->definitions->propose($this->definitionInput(), $this->staff());
        $reviewer = $this->staff();
        $this->refuses(fn () => $this->definitions->approve($proposed['id'], ['definitionHash' => str_repeat('0', 64)], $reviewer), 'stale_definition');
        $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer);
        $this->refuses(fn () => $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $this->staff()), 'already_reviewed');
        $this->assertSame(1, DB::table('production_free_reviews')->count());
    }

    public function test_staff_matrix_refuses_customers_unverified_staff_and_missing_mfa(): void
    {
        $customer = $this->staff(attributes: ['is_admin' => false]);
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $customer), 'staff_refused');
        $unverified = $this->staff(attributes: ['email_verified_at' => null]);
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $unverified), 'staff_refused');
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $this->staff(mfa: false)), 'mfa_required');
        $this->assertSame(0, DB::table('production_free_definitions')->count());
    }

    public function test_a_stale_actor_model_after_demotion_or_mfa_removal_is_refused(): void
    {
        $proposed = $this->definitions->propose($this->definitionInput(), $this->staff());
        $reviewer = $this->staff();
        DB::table('users')->where('id', $reviewer->id)->update(['is_admin' => false]);
        $this->refuses(fn () => $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer), 'staff_refused');
        $other = $this->staff();
        $stale = clone $other;
        DB::table('users')->where('id', $other->id)->update(['app_authentication_secret' => null]);
        $this->refuses(fn () => $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $stale), 'staff_refused');
        $this->refuses(fn () => $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $other->fresh()), 'mfa_required');
        $this->assertSame(0, DB::table('production_free_reviews')->count());
    }

    public function test_open_requires_review_sean_approved_terms_ready_sources_and_the_expected_ordinal(): void
    {
        $author = $this->staff();
        $reviewer = $this->staff();
        $proposed = $this->definitions->propose($this->definitionInput(), $author);
        $input = ['definitionHash' => $proposed['definitionHash'], 'expectedOrdinal' => 0];
        $this->refuses(fn () => $this->definitions->open($proposed['id'], $input, $reviewer), 'not_reviewed');
        $this->definitions->approve($proposed['id'], ['definitionHash' => $proposed['definitionHash']], $reviewer);
        config(['production-free-grants.approved_terms_hashes' => []]);
        $this->refuses(fn () => $this->definitions->open($proposed['id'], $input, $reviewer), 'stale_terms');
        config(['production-free-grants.approved_terms_hashes' => [hash('sha256', self::SYNTHETIC_TERMS)]]);
        $this->sources->ready = false;
        $this->refuses(fn () => $this->definitions->open($proposed['id'], $input, $reviewer), 'source_not_ready');
        $this->sources->ready = true;
        $this->refuses(fn () => $this->definitions->open($proposed['id'], [...$input, 'expectedOrdinal' => 1], $reviewer), 'stale_availability');
        $this->refuses(fn () => $this->definitions->close($proposed['id'], $input, $reviewer), 'stale_availability');
        $this->assertSame(0, DB::table('production_free_availability')->count());
        $this->assertTrue($this->definitions->open($proposed['id'], $input, $reviewer)['open']);
    }

    public function test_proposal_refuses_unready_sources_and_loose_input(): void
    {
        $author = $this->staff();
        $this->sources->ready = false;
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $author), 'source_not_ready');
        $this->sources->ready = true;
        $input = $this->definitionInput();
        foreach ([
            [...$input, 'extra' => true],
            [...$input, 'maxOrigins' => '5'],
            [...$input, 'termsText' => "Synthetic\x07terms"],
            [...$input, 'assets' => []],
            [...$input, 'assets' => [$input['assets'][0], $input['assets'][0]]],
            [...$input, 'assets' => [[...$input['assets'][0], 'role' => 'preview_mp3']]],
            [...$input, 'assets' => [[...$input['assets'][0], 'sha256' => strtoupper($input['assets'][0]['sha256'])]]],
            [...$input, 'source' => ['license_id' => 'only']],
            [...$input, 'source' => [...$input['source'], 'Bad Key' => 'x']],
        ] as $index => $bad) {
            $this->refuses(fn () => $this->definitions->propose($bad, $author), 'invalid_input', (string) $index);
        }
        $this->assertSame(0, DB::table('production_free_definitions')->count());
    }

    public function test_policy_matrix_disabled_environment_provenance_and_unbound_capability(): void
    {
        $author = $this->staff();
        config(['production-free-grants.enabled' => false]);
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $author), 'disabled');
        config(['production-free-grants.enabled' => true, 'production-free-grants.provenance' => 'verified_production']);
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $author), 'provenance');
        config(['production-free-grants.provenance' => 'synthetic_rehearsal', 'production-free-grants.authorization_ttl_seconds' => 3600]);
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $author), 'changed_policy');
        config(['production-free-grants.authorization_ttl_seconds' => 300]);
        $this->app->forgetInstance(ProductionFreeGrantSources::class);
        $this->app->offsetUnset(ProductionFreeGrantSources::class);
        $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $author), 'capability_absent');
        $this->app->instance(ProductionFreeGrantSources::class, $this->sources);
        $this->app['env'] = 'production';
        try {
            $this->refuses(fn () => $this->definitions->propose($this->definitionInput(), $author), 'environment');
        } finally {
            $this->app['env'] = 'testing';
        }
        $this->assertSame(0, DB::table('production_free_definitions')->count());
        $this->assertNotEmpty($this->definitions->propose($this->definitionInput(), $author)['id']);
    }

    public function test_shipped_configuration_is_literally_default_off(): void
    {
        $shipped = require base_path('config/production-free-grants.php');
        $this->assertFalse($shipped['enabled']);
        $this->assertNull($shipped['provenance']);
        $this->assertSame([], $shipped['approved_terms_hashes']);
        $this->assertNull($shipped['storage_root']);
        // Nothing registers, binds or mounts family 256; root composes it after review.
        $shared = file_get_contents(base_path('bootstrap/providers.php')).file_get_contents(base_path('routes/web.php')).file_get_contents(base_path('config/app.php'));
        foreach (glob(base_path('app/Providers/*.php')) as $provider) {
            $shared .= file_get_contents($provider);
        }
        $this->assertStringNotContainsString('ProductionFree', $shared);
    }

    private function refuses(callable $operation, string $reason, string $message = ''): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason.' '.$message);
        } catch (ProductionFreeGrantException $error) {
            $this->assertSame($reason, $error->reason, $message);
        }
    }
}
