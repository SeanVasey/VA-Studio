<?php

namespace Tests\Feature\ProductionFreeGrants;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDefinitions;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDocuments;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantDownloads;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantException;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantInput;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantLibrary;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRecords;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRenderProfile;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantRows;
use App\Domain\Grants\ProductionFree\ProductionFreeGrants;
use App\Domain\Grants\ProductionFree\ProductionFreeGrantTransfer;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\Support\ProductionFreeGrantFixtures;
use Tests\TestCase;

/**
 * F-1: a legitimate renderer revision changes the runtime profile. Existing grants are verified against their own
 * sealed profile and the registry of released profiles, so they stay readable and deliverable. Only rendering,
 * recovery and new assent need the runtime to equal the profile. The revision is simulated the way the
 * independent review did (mutation U3): the sealed implementation manifest the runtime reads no longer matches.
 */
final class ProductionFreeGrantProfileRevisionTest extends TestCase
{
    use ProductionFreeGrantFixtures;

    private string $originalBase;

    private ?string $revisedRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeSetup();
        $this->originalBase = $this->app->make('path.base');
        $this->beforeApplicationDestroyed(function (): void {
            if ($this->revisedRoot !== null && is_dir($this->revisedRoot)) {
                $this->removeTree($this->revisedRoot);
            }
        });
    }

    public function test_an_existing_rendered_origin_stays_readable_and_deliverable_after_a_profile_revision(): void
    {
        $definition = $this->openDefinition();
        [$owner, $origin] = $this->granted($definition['id'], 'owner-a@example.test');
        (new ProductionFreeGrantDocuments)->render($origin['id']);
        $this->reviseRuntime();
        $this->refuses(fn () => ProductionFreeGrantRenderProfile::current('synthetic_rehearsal'), 'profile_changed', ContractIssuanceException::class);

        $library = new ProductionFreeGrantLibrary;
        $this->assertSame(1, $library->index($owner['principal'], $owner['user'])['total']);
        $shown = $library->show($origin['id'], $owner['principal'], $owner['user']);
        $this->assertSame('complete', $shown['documentStatus']);
        $this->assertTrue($shown['deliverable']);
        $downloads = new ProductionFreeGrantDownloads;
        foreach (['contract', 'master_wav'] as $role) {
            $authorization = $downloads->authorize($origin['id'], ['originSeal' => $shown['originSeal'], 'role' => $role], $owner['principal'], $owner['user']);
            $bytes = $this->bytes($downloads->redeem($authorization['id'], $authorization['token'], $owner['principal'], $owner['user']));
            $this->assertSame($authorization['sha256'], hash('sha256', $bytes));
            $this->assertSame($authorization['bytes'], strlen($bytes));
        }
        $this->assertSame(2, DB::table('production_free_redemptions')->count());
        $staff = (new ProductionFreeGrantDefinitions)->read($definition['id'], $this->staff());
        $this->assertSame($definition['definitionHash'], $staff['definitionHash']);
        $revoked = (new ProductionFreeGrants)->revoke($origin['id'], ['originSeal' => $shown['originSeal'], 'reason' => 'Synthetic revocation after a profile revision.'], $this->staff());
        $this->assertTrue($revoked['revoked']);
    }

    public function test_render_and_recover_need_the_current_profile_and_burn_no_claim_when_refused(): void
    {
        $definition = $this->openDefinition();
        [, $rendered] = $this->granted($definition['id'], 'owner-a@example.test');
        [, $pending] = $this->granted($definition['id'], 'owner-b@example.test');
        $documents = new ProductionFreeGrantDocuments;
        $before = $documents->render($rendered['id']);
        $this->reviseRuntime();

        $this->refuses(fn () => $documents->render($pending['id']), 'profile_changed');
        $this->assertSame(0, DB::table('production_free_document_work')->where('origin_id', $pending['id'])->count());
        $this->refuses(fn () => $documents->recover($rendered['id']), 'profile_changed');
        // A published original is returned unchanged: reading it never needs the runtime.
        $this->assertSame($before, $documents->render($rendered['id']));

        $this->restoreRuntime();
        $this->assertSame('complete', $documents->render($pending['id'])['documentStatus']);
        $this->assertSame(['claimed'], DB::table('production_free_document_work')->where('origin_id', $pending['id'])->pluck('kind')->all());
        $this->assertTrue($documents->recover($rendered['id'])['identical']);
    }

    public function test_new_assent_and_reopening_need_the_current_profile_but_closing_does_not(): void
    {
        $definition = $this->openDefinition();
        $newcomer = $this->customer('newcomer@example.test');
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definition['id'], 'Declared Synthetic Buyer', $newcomer['principal'], $newcomer['user']);
        $input = $this->assentInput($review);
        $this->reviseRuntime();

        $this->refuses(fn () => $grants->review($definition['id'], 'Declared Synthetic Buyer', $newcomer['principal'], $newcomer['user']), 'profile_changed');
        $this->refuses(fn () => $grants->accept($definition['id'], $input, $newcomer['principal'], $newcomer['user']), 'profile_changed');
        $this->assertSame(0, DB::table('production_free_origins')->count());
        $definitions = new ProductionFreeGrantDefinitions;
        $closed = $definitions->close($definition['id'], ['definitionHash' => $definition['definitionHash'], 'expectedOrdinal' => 1], $this->staff());
        $this->assertFalse($closed['open']);
        $this->refuses(fn () => $definitions->open($definition['id'], ['definitionHash' => $definition['definitionHash'], 'expectedOrdinal' => 2], $this->staff()), 'profile_changed');
        $this->assertSame(2, DB::table('production_free_availability')->count());
    }

    public function test_a_tampered_or_unregistered_stored_profile_is_still_refused(): void
    {
        foreach (['synthetic_rehearsal', 'verified_production'] as $provenance) {
            $profile = ProductionFreeGrantRenderProfile::current($provenance);
            $this->assertSame($profile, ProductionFreeGrantRenderProfile::validateStored($profile));
            $implementation = $profile;
            $implementation['implementation']['files']['scripts/render-production-free-grant.php'] = str_repeat('0', 64);
            $limits = $profile;
            $limits['limits']['pages'] = $limits['limits']['pages'] + 1;
            $label = $profile;
            $label['template'] = 'production-free-grant-sections-v2';
            $missing = $profile;
            unset($missing['base']);
            $extra = [...$profile, 'unreviewed' => true];
            foreach ([$implementation, $limits, $label, $missing, $extra, []] as $forged) {
                $this->refuses(fn () => ProductionFreeGrantRenderProfile::validateStored($forged), 'profile_changed', ContractIssuanceException::class);
            }
        }
    }

    public function test_a_properly_sealed_row_carrying_an_unregistered_or_mismatched_profile_is_refused_on_read(): void
    {
        $definitions = new ProductionFreeGrantDefinitions;
        $staff = $this->staff();
        $good = $definitions->propose($this->definitionInput(), $staff);
        $row = (array) DB::table('production_free_definitions')->where('id', $good['id'])->first();
        $forgeries = [
            // A profile no release ever sealed.
            function (array $payload): array {
                $payload['profile']['implementation']['files']['scripts/render-production-free-grant.php'] = str_repeat('0', 64);

                return $payload;
            },
            // A released profile of the other provenance than the definition it is sealed into.
            function (array $payload): array {
                $payload['profile'] = ProductionFreeGrantRenderProfile::current('verified_production');

                return $payload;
            },
        ];
        foreach ($forgeries as $forge) {
            $at = ProductionFreeGrantInput::now();
            $payload = ProductionFreeGrantRecords::decrypt($row);
            $payload['definition_id'] = (string) Str::uuid();
            $payload['proposed_at'] = ProductionFreeGrantInput::iso($at);
            $payload = $forge($payload);
            $columns = (new ReflectionMethod(ProductionFreeGrantDefinitions::class, 'columns'))->invoke($definitions, $payload, $at);
            DB::transaction(fn () => (new ProductionFreeGrantRows)->insert('production_free_definitions', $columns, $payload));

            $this->refuses(fn () => $definitions->read($payload['definition_id'], $staff), 'profile_unregistered');
        }
        $this->assertSame($good['id'], $definitions->read($good['id'], $staff)['id']);
    }

    public function test_every_current_profile_is_in_the_release_registry(): void
    {
        foreach (['synthetic_rehearsal', 'verified_production'] as $provenance) {
            $hash = CanonicalJson::hash(ProductionFreeGrantRenderProfile::current($provenance));
            $released = array_column(ProductionFreeGrantRenderProfile::RELEASED, $provenance);
            $this->assertContains($hash, $released, 'A renderer revision must append its profile hash to RELEASED.');
            $this->assertSame($released, array_values(array_unique($released)));
        }
    }

    /** @return array{0:array,1:array} */
    private function granted(string $definitionId, string $email): array
    {
        $owner = $this->customer($email);
        $grants = new ProductionFreeGrants;
        $review = $grants->review($definitionId, 'Declared Synthetic Buyer', $owner['principal'], $owner['user']);

        return [$owner, $grants->accept($definitionId, $this->assentInput($review), $owner['principal'], $owner['user'])];
    }

    /** Mutation U3: the sealed implementation manifest the runtime reads changes, so `current()` refuses. */
    private function reviseRuntime(): void
    {
        $this->revisedRoot = realpath(sys_get_temp_dir()).'/va-free256-revised-'.bin2hex(random_bytes(6));
        mkdir($this->revisedRoot.'/resources/contracts/production-free-v1', 0700, true);
        $manifest = $this->revisedRoot.'/resources/contracts/production-free-v1/profile-assets.json';
        copy($this->originalBase.'/resources/contracts/production-free-v1/profile-assets.json', $manifest);
        file_put_contents($manifest, "\n", FILE_APPEND);
        $this->app->instance('path.base', $this->revisedRoot);
    }

    private function restoreRuntime(): void
    {
        $this->app->instance('path.base', $this->originalBase);
    }

    private function bytes(ProductionFreeGrantTransfer $transfer): string
    {
        $bytes = '';
        $transfer->writeTo(function (string $chunk) use (&$bytes): void {
            $bytes .= $chunk;
        });

        return $bytes;
    }

    private function refuses(callable $operation, string $reason, string $class = ProductionFreeGrantException::class): void
    {
        try {
            $operation();
            $this->fail('Must refuse '.$reason);
        } catch (ProductionFreeGrantException|ContractIssuanceException $error) {
            $this->assertInstanceOf($class, $error);
            $this->assertSame($reason, $error->reason ?? $error->getMessage());
        }
    }
}
