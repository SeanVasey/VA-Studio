<?php

namespace App\Console\Commands;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\SoundKitContracts;
use App\Domain\SoundKits\Purchases\SoundKitPurchasePolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/** BL-13: issue the kit license contract originals of paid kit grants (bounded). Never re-renders a recorded original. */
final class IssueTestKitContracts extends Command
{
    protected $signature = 'vasey:issue-test-kit-contracts {grant?} {--limit=25}';

    protected $description = 'Issue sound-kit license contract originals for paid Stripe-test kit grants.';

    public function handle(): int
    {
        $id = $this->argument('grant');
        $limit = $this->option('limit');
        if (! app(SoundKitPurchasePolicy::class)->admitted() || ! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100 || ($id !== null && ! Str::isUuid($id))) {
            $this->error('Kit contract issuance is unavailable or the request is invalid.');

            return self::FAILURE;
        }
        $query = SoundKitGrant::query()->whereNotExists(fn ($contracts) => $contracts->selectRaw('1')->from('sound_kit_contracts')
            ->whereColumn('sound_kit_contracts.sound_kit_grant_id', 'sound_kit_grants.id'));
        $grants = $id !== null ? SoundKitGrant::query()->where('public_id', $id)->get() : $query->orderBy('id')->limit((int) $limit)->get();
        if ($id !== null && $grants->isEmpty()) {
            $this->error('The request is invalid.');

            return self::FAILURE;
        }
        $failed = false;
        foreach ($grants as $grant) {
            try {
                $contract = app(SoundKitContracts::class)->issue($grant);
                $this->line($grant->public_id.' issued '.$contract->sha256);
            } catch (Throwable $error) {
                $failed = true;
                $reason = $error instanceof ContractIssuanceException ? $error->reason : 'unavailable';
                $this->line($grant->public_id.' refused '.$reason);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
