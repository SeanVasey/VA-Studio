<?php

namespace App\Console\Commands;

use App\Domain\Commerce\Models\LicenseGrant;
use App\Domain\Commerce\Orders\OrderRequest;
use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\ContractIssuancePolicy;
use App\Domain\Contracts\RenderTestContract;
use App\Domain\Contracts\RequestTestContract;
use Illuminate\Console\Command;
use Throwable;

final class IssueTestContracts extends Command
{
    protected $signature = 'vasey:issue-test-contracts {grant?} {--limit=25} {--after=}';
    protected $description = 'Issue bounded original test contracts from retained paid grants.';

    public function handle(): int
    {
        try {
            $policy = app(ContractIssuancePolicy::class); $policy->current(); $account = $policy->account();
            ContractIssuancePolicy::outsideTransactions();
            $id = $this->argument('grant'); $cursor = $this->option('after'); $limit = $this->option('limit');
            if (! is_string($limit) || ! ctype_digit($limit) || (int) $limit < 1 || (int) $limit > 100
                || ($id !== null && ! OrderRequest::uuid($id)) || ($cursor !== null && ! OrderRequest::uuid($cursor))
                || ($id !== null && $cursor !== null)) { throw new \InvalidArgumentException; }
            $query = LicenseGrant::query()->select('license_grants.*')
                ->join('order_finalizations', 'order_finalizations.id', '=', 'license_grants.order_finalization_id')
                ->join('verified_payments', 'verified_payments.id', '=', 'order_finalizations.verified_payment_id')
                ->where('order_finalizations.outcome', 'paid')->where('order_finalizations.mode', 'test')
                ->where('verified_payments.mode', 'test')->where('verified_payments.account_id', $account);
            if ($id !== null) {
                $grants = (clone $query)->where('license_grants.public_id', $id)->get();
                if ($grants->isEmpty()) { throw new \InvalidArgumentException; }
            } else {
                $after = $cursor === null ? 0 : (clone $query)->where('license_grants.public_id', $cursor)->value('license_grants.id');
                if ($after === null) { throw new \InvalidArgumentException; }
                $at = now()->toImmutable()->utc()->startOfSecond();
                $grants = $query->where('license_grants.id', '>', $after)
                    ->where(function ($eligible) use ($at): void {
                        // A missing work row is corruption even if an original manifest survived.
                        // Include it for a bounded failure; RequestTestContract must never recreate it.
                        $eligible->whereExists(function ($requests): void {
                            $requests->selectRaw('1')->from('contract_render_requests')
                                ->whereColumn('contract_render_requests.license_grant_id', 'license_grants.id')
                                ->whereNotExists(fn ($work) => $work->selectRaw('1')->from('contract_render_work')
                                    ->whereColumn('contract_render_work.contract_render_request_id', 'contract_render_requests.id'));
                        })->orWhere(function ($unfinished) use ($at): void {
                            $unfinished->whereNotExists(fn ($originals) => $originals->selectRaw('1')->from('grant_contracts')
                                ->whereColumn('grant_contracts.license_grant_id', 'license_grants.id'))
                                ->where(function ($missingOrDue) use ($at): void {
                                    $missingOrDue->whereNotExists(fn ($requests) => $requests->selectRaw('1')->from('contract_render_requests')
                                        ->whereColumn('contract_render_requests.license_grant_id', 'license_grants.id'))
                                        ->orWhereExists(function ($requests) use ($at): void {
                                            $requests->selectRaw('1')->from('contract_render_requests')
                                                ->join('contract_render_work', 'contract_render_work.contract_render_request_id', '=', 'contract_render_requests.id')
                                                ->whereColumn('contract_render_requests.license_grant_id', 'license_grants.id')
                                                ->where(function ($due) use ($at): void {
                                                    $due->where('contract_render_work.state', 'pending')
                                                        ->orWhere(fn ($retry) => $retry->where('contract_render_work.state', 'retry')
                                                            ->where('contract_render_work.next_attempt_at', '<=', $at))
                                                        ->orWhere(fn ($expired) => $expired->where('contract_render_work.state', 'processing')
                                                            ->where('contract_render_work.lease_expires_at', '<=', $at));
                                                });
                                        });
                                });
                        });
                    })->orderBy('license_grants.id')->limit((int) $limit)->get();
            }
            foreach ($grants as $grant) {
                $this->line($grant->public_id.' '.$this->issue((int) $grant->id));
            }
            if ($id === null && $grants->isNotEmpty()) { $this->line('NEXT_AFTER='.$grants->last()->public_id); }

            return self::SUCCESS;
        } catch (Throwable) {
            $this->error('Test contract issuance is unavailable or the request is invalid.');

            return self::FAILURE;
        }
    }

    private function issue(int $grantId): string
    {
        try {
            $request = app(RequestTestContract::class)->handle($grantId);
            $outcome = app(RenderTestContract::class)->handle($request->id);

            return in_array($outcome, ['ready', 'pending', 'retry', 'quarantined', 'busy', 'unavailable', 'changed', 'original_unavailable', 'stale'], true)
                ? $outcome : 'retry';
        } catch (ContractIssuanceException $error) {
            return match ($error->reason) {
                'unavailable' => 'unavailable', 'evidence_changed', 'profile_changed' => 'changed', default => 'retry',
            };
        } catch (Throwable) { return 'retry'; }
    }
}
