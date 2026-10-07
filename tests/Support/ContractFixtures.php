<?php

namespace Tests\Support;

use App\Domain\Commerce\Finalization\FinalizeTestPayment;
use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Models\OrderFinalization;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Contracts\ContractRenderer;
use App\Domain\Contracts\ContractRenderProfile;
use App\Domain\Contracts\ContractText;
use App\Domain\Contracts\RenderedContract;
use App\Support\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Synthetic paid-grant fixtures. The synthetic PDF tests orchestration, not PDF rendering compliance. */
final class ContractFixtures
{
    public static function configure(): void
    {
        FinalizationFixtures::configure();
        config(['contracts.test_issuance_enabled' => true,
            'contracts.test_issuance_policy' => json_encode(self::policy(), JSON_THROW_ON_ERROR)]);
        chmod(Storage::disk('local')->path(''), 0700);
    }

    public static function policy(): array
    {
        return ContractIssuancePolicy::CONTRACT;
    }

    public static function paid(object $gateway, bool $mixed = false): array
    {
        $fixture = $mixed ? FinalizationFixtures::confirmedMixedCart($gateway) : FinalizationFixtures::confirmed($gateway, true, true);

        return self::finalize($fixture);
    }

    public static function finalize(array $fixture): array
    {
        $outcome = app(FinalizeTestPayment::class)->handle($fixture['payment']->id);
        if ($outcome !== 'paid') {
            throw new \LogicException('Synthetic payment did not finalize: '.$outcome);
        }
        $finalization = OrderFinalization::where('order_id', $fixture['order']->id)->sole();
        $grants = LicenseGrant::where('order_finalization_id', $finalization->id)->orderBy('order_line_id')->get();

        return $fixture + ['finalization' => $finalization, 'grants' => $grants, 'grant' => $grants->first() ?? throw new \LogicException('Synthetic paid order has no grant.')];
    }

    public static function retained(): array
    {
        $rows = FinalizationFixtures::retained();
        foreach (['contract_render_requests', 'contract_render_work', 'grant_contracts'] as $table) {
            $rows[$table] = json_encode(DB::table($table)->orderBy('id')->get(), JSON_THROW_ON_ERROR);
        }

        return $rows;
    }

    public static function renderer(): ContractRenderer
    {
        return new class implements ContractRenderer
        {
            public array $calls = [];

            public mixed $onRender = null;

            public function render(array $input, array $profile): RenderedContract
            {
                $this->calls[] = ['input' => $input, 'profile' => $profile, 'transaction_level' => DB::transactionLevel()];
                if ($this->onRender !== null) {
                    return ($this->onRender)($input, $profile);
                }

                return ContractFixtures::syntheticResult($input, $profile);
            }
        };
    }

    public static function syntheticResult(array $input, array $profile): RenderedContract
    {
        // Deliberately not represented as a standards-validated PDF; real renderer tests own that claim.
        $digest = CanonicalJson::hash($input);
        $bytes = "%PDF-1.7\n% synthetic orchestration fixture\n% {$digest}\n%%EOF\n";

        return new RenderedContract($bytes, hash('sha256', $bytes), strlen($bytes), 1, app(ContractText::class)->build($input)['text_digest'],
            ContractRenderProfile::hash($profile));
    }
}
