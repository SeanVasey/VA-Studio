<?php

namespace Tests\Feature\ProductionFeatures;

use App\Domain\Customers\Preferences\ConsentException;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentEvent;
use App\Domain\Customers\ProductionFeatures\Models\ProductionConsentState;
use App\Domain\Customers\ProductionFeatures\Preferences\ProductionConsentPreferences;
use App\Domain\Customers\ProductionFeatures\ProductionFeatureException;
use App\Support\CanonicalJson;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\ProductionFeatureFixtures;
use Tests\TestCase;

class ProductionConsentGraphTest extends TestCase
{
    use ProductionFeatureFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->featureSetup();
    }

    public function test_grants_retain_older_withdrawal_pointer_and_never_claim_provider_unsuppression(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionWithdraw());
        $pointer = DB::table('production_consent_states')->value('withdrawal_event_id');
        $preferences->change($owner, $this->productionGrant(1));
        $again = $preferences->change($owner, $this->productionGrant(2));
        $this->assertSame('granted', $again['preferences']['purposes'][0]['status']);
        $this->assertSame('pending', $again['preferences']['purposes'][0]['suppression']['status']);
        $this->assertSame($pointer, DB::table('production_consent_states')->value('withdrawal_event_id'));
        $this->assertSame($again, $preferences->read($owner));
        $this->assertSame(3, DB::table('production_consent_events')->count());
        $this->assertSame(1, DB::table('production_account_feature_bindings')->count());
    }

    public function test_changed_notice_never_reinterprets_captured_policy_but_withdrawal_still_works(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        $preferences->change($owner, $this->productionGrant());
        $oldPolicy = (array) DB::table('production_consent_policies')->sole();
        config(['production-customer-preferences.email_marketing.notice' => 'SYNTHETIC changed text under the same retained notice version']);
        $changed = $preferences->read($owner)['preferences']['purposes'][0];
        $this->assertSame('unknown', $changed['status']);
        $this->assertFalse($changed['canGrant']);
        $withdraw = $preferences->change($owner, $this->productionWithdraw(1));
        $this->assertSame('withdrawn', $withdraw['preferences']['purposes'][0]['status']);
        $this->assertSame('pending', $withdraw['preferences']['purposes'][0]['suppression']['status']);
        $this->assertSame($oldPolicy, (array) DB::table('production_consent_policies')->sole());
    }

    public function test_client_recipient_and_history_fields_are_rejected_without_new_choice_or_binding(): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        foreach (['email', 'recipient', 'recipient_hmac', 'binding', 'origin_public_id', 'withdrawal_event_id'] as $field) {
            try {
                $preferences->change($owner, $this->productionWithdraw() + [$field => 'SYNTHETIC forged input']);
                $this->fail('Client cannot provide server recipient or history authority.');
            } catch (ConsentException $error) {
                $this->assertSame(422, $error->status);
                $this->assertStringNotContainsString('forged', $error->getMessage());
            }
        }
        $this->assertSame(0, DB::table('production_consent_events')->count());
        $this->assertSame(0, DB::table('production_consent_states')->count());
    }

    public static function nullPointers(): array
    {
        return [['first_withdrawal'], ['grant_after_withdrawal']];
    }

    #[DataProvider('nullPointers')]
    public function test_nullable_pointer_cannot_bypass_insert_or_update_guard_with_sql_unknown(string $case): void
    {
        $owner = $this->featureIdentity('consent_preferences');
        $preferences = new ProductionConsentPreferences;
        $preferences->initialize($owner);
        if ($case === 'first_withdrawal') {
            $beforeBinding = (array) DB::table('production_account_feature_bindings')->sole();
            $fired = false;
            $attempted = false;
            DB::connection()->beforeExecuting(function (string $sql, array $values) use (&$attempted, $beforeBinding) {
                if (! str_starts_with(strtolower($sql), 'insert into') || ! str_contains($sql, 'production_consent_states')) {
                    return;
                }
                preg_match('/\(([^)]+)\) values/i', $sql, $match);
                $columns = array_map(fn ($column) => trim($column, ' "`'), explode(',', $match[1]));
                $attributes = array_combine($columns, $values);
                $event = (array) DB::table('production_consent_events')->sole();
                $this->assertSame((int) $beforeBinding['id'], (int) $attributes['binding_id']);
                $this->assertSame(1, (int) $attributes['revision']);
                $this->assertSame((int) $event['id'], (int) $attributes['event_id']);
                $this->assertSame('withdrawn', $event['status']);
                $this->assertSame($event['created_at'], $attributes['created_at']);
                $this->assertNull($attributes['withdrawal_event_id']);
                $attempted = true;
            });
            ProductionConsentState::creating(function (ProductionConsentState $model) use (&$fired) {
                $fired = true;
                $model->withdrawal_event_id = null;
            });
            try {
                $preferences->change($owner, $this->productionWithdraw());
                $this->fail('The real first-withdrawal INSERT must reject SQL UNKNOWN.');
            } catch (ProductionFeatureException $error) {
                $this->assertSame(503, $error->status);
                $this->assertTrue($fired);
                $this->assertTrue($attempted, 'The actual otherwise-valid pointer INSERT must reach the database guard.');
                $this->assertSame(0, DB::table('production_consent_events')->count());
                $this->assertSame(0, DB::table('production_consent_states')->count());
                $this->assertSame($beforeBinding, (array) DB::table('production_account_feature_bindings')->sole());
            } finally {
                ProductionConsentState::flushEventListeners();
            }
        } else {
            $preferences->change($owner, $this->productionGrant());
            $preferences->change($owner, $this->productionWithdraw(1));
            $beforeState = (array) DB::table('production_consent_states')->sole();
            $beforeEvents = DB::table('production_consent_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $inserted = false;
            try {
                DB::transaction(function () use ($beforeEvents, $beforeState, &$inserted) {
                    $grant = $beforeEvents[0];
                    unset($grant['id']);
                    $grant['public_id'] = (string) Str::uuid();
                    $grant['revision'] = 3;
                    $grant['created_at'] = now()->utc()->format('Y-m-d H:i:s');
                    $capture = json_decode(Crypt::decryptString($grant['recipient_ciphertext']), true, 16, JSON_THROW_ON_ERROR);
                    $capture['revision'] = 3;
                    $capture['eventId'] = $grant['public_id'];
                    $grant['recipient_ciphertext'] = Crypt::encryptString(CanonicalJson::encode($capture));
                    $event = ProductionConsentEvent::create($grant);
                    $inserted = true;
                    DB::table('production_consent_states')->where('id', $beforeState['id'])->update([
                        'revision' => 3, 'event_id' => $event->id, 'withdrawal_event_id' => null, 'updated_at' => $grant['created_at'],
                    ]);
                });
                $this->fail('SQL UNKNOWN must reject clearing a retained withdrawal pointer.');
            } catch (QueryException $error) {
                $this->assertTrue($inserted, 'A valid next grant must be inserted before the invalid pointer UPDATE.');
                $this->assertStringContainsString('production_consent_states', $error->getSql());
                $this->assertSame($beforeState, (array) DB::table('production_consent_states')->sole());
                $this->assertSame($beforeEvents, DB::table('production_consent_events')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
            }
        }
    }
}
