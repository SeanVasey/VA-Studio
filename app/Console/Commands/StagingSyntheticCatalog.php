<?php

namespace App\Console\Commands;

use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalBuilder;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalCatalog;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalPolicy;
use App\Domain\Catalog\SyntheticRehearsal\SyntheticRehearsalRefusal;
use App\Domain\Media\MediaFailure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

/**
 * Seeds the labelled synthetic staging rehearsal catalog (about six tracks) through the real domain services, so testers
 * can rehearse catalog → player → quote → Stripe test checkout → verified payment → contract → download before real
 * tracks exist. Default off, refused in production unconditionally, and refused unless Stripe is in test mode.
 */
final class StagingSyntheticCatalog extends Command
{
    private const LOCK = 'vasey:staging-synthetic-catalog';

    protected $signature = 'vasey:staging-synthetic-catalog
        {--operator= : Email of the synthetic catalog operator (author, rights verifier and publisher)}
        {--reviewer= : Email of the independent synthetic license reviewer}
        {--check : Run the admission checks and print the plan without writing anything}';

    protected $description = 'Create the labelled synthetic staging rehearsal catalog (local, testing or staging only; default off).';

    public function handle(SyntheticRehearsalPolicy $policy, SyntheticRehearsalBuilder $builder): int
    {
        try {
            $policy->admit();
            [$operator, $reviewer] = $policy->operators($this->option('operator'), $this->option('reviewer'));
            $policy->media();
        } catch (SyntheticRehearsalRefusal $refusal) {
            $this->error('Refused: '.$refusal->getMessage().' Nothing was written.');

            return self::FAILURE;
        }
        $this->warn(SyntheticRehearsalCatalog::LABEL.'. Environment: '.app()->environment().'.');
        if ($this->option('check')) {
            $this->line('Admission passed. Operator #'.$operator->id.', reviewer #'.$reviewer->id.'. Nothing was written.');
            foreach (SyntheticRehearsalCatalog::licenses() as $license) {
                $this->line('license '.$license['slug'].'  '.$license['name'].'  '.number_format($license['price_minor'] / 100, 2).' '.SyntheticRehearsalCatalog::CURRENCY);
            }
            foreach (SyntheticRehearsalCatalog::tracks() as $track) {
                $this->line('track   '.$track['slug'].'  '.$track['title'].'  '.$track['duration'].' s');
            }

            return self::SUCCESS;
        }
        $lock = Cache::lock(self::LOCK, 3600);
        if (! $lock->get()) {
            $this->error('Refused: another synthetic catalog run holds the lock. Nothing was written.');

            return self::FAILURE;
        }
        try {
            $licenses = $builder->licenses($operator, $reviewer);
            foreach ($licenses as $key => $license) {
                $this->line('license '.SyntheticRehearsalCatalog::licenses()[$key]['slug'].'  '.$license['state'].'  version #'.$license['version']->id);
            }
            $rows = [];
            $ready = true;
            foreach (SyntheticRehearsalCatalog::tracks() as $definition) {
                $result = $builder->track($definition, $licenses, $operator);
                $ready = $ready && $result['ready'];
                $offers = [];
                foreach ($result['revisions'] as $key => $revision) {
                    $offers[] = strtoupper($key).' revision #'.$revision->id.' at '.number_format($revision->price_minor / 100, 2).' '.$revision->currency;
                }
                $rows[] = [$definition['slug'], $result['state'], implode('; ', $offers) ?: '-'];
                $this->line('track   '.$definition['slug'].'  '.$result['state']);
            }
        } catch (SyntheticRehearsalRefusal $refusal) {
            $this->error('Stopped: '.$refusal->getMessage());

            return self::FAILURE;
        } catch (ValidationException $exception) {
            $this->error('Stopped by a domain guard: '.implode(' ', $exception->validator->errors()->all()));

            return self::FAILURE;
        } catch (AuthorizationException $exception) {
            $this->error('Stopped by staff authorization: '.($exception->getMessage() ?: 'This action is unauthorized.'));

            return self::FAILURE;
        } catch (MediaFailure $failure) {
            $this->error('Stopped by the media pipeline: '.$failure->failureCode.'.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
        $this->table(['Track', 'State', 'Offers (TEST PRICE)'], $rows);
        $this->line('Before \'Prepare order\', link each offer revision to a synthetic rights scope with `php artisan vasey:rights-scope` '
            .'(docs/ops/staging-test-purchase.md, setup step 5). Reset staging before loading real content (docs/ops/staging-runbook.md).');

        return $ready ? self::SUCCESS : self::FAILURE;
    }
}
