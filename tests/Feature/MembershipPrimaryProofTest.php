<?php

namespace Tests\Feature;

use App\Domain\Memberships\CreditLedger;
use App\Domain\Memberships\MembershipPlans;
use App\Domain\Memberships\Models\MembershipCreditBucket;
use App\Support\Audit\AuditEvent;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CustomerFixtures;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\MembershipFixtures as F;
use Tests\TestCase;

class MembershipPrimaryProofTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    public static function authorityCases(): array
    {
        return [
            'new grant role' => ['grant', 'role'], 'new grant email' => ['grant', 'email'],
            'new grant MFA' => ['grant', 'mfa'], 'new grant account' => ['grant', 'account'],
            'new plan role' => ['plan-create', 'role'], 'new plan MFA' => ['plan-create', 'mfa'],
            'reviewed plan role' => ['plan-apply', 'role'], 'reviewed plan MFA' => ['plan-apply', 'mfa'],
            'reserve account' => ['reserve', 'account'], 'reserve credential' => ['reserve', 'password'],
            'consume account' => ['consume', 'account'], 'consume credential' => ['consume', 'password'],
            'grant replay role' => ['grant-replay', 'role'], 'grant replay MFA' => ['grant-replay', 'mfa'],
            'grant replay account' => ['grant-replay', 'account'],
            'read account' => ['read', 'account'], 'read credential' => ['read', 'password'],
            'read customer role' => ['read', 'customer-role'],
            'reserve replay account' => ['reserve-replay', 'account'], 'reserve replay credential' => ['reserve-replay', 'password'],
            'plan no-op role' => ['plan-noop', 'role'], 'plan no-op MFA' => ['plan-noop', 'mfa'],
            'plan review role' => ['plan-review', 'role'], 'plan review MFA' => ['plan-review', 'mfa'],
        ];
    }

    #[DataProvider('authorityCases')]
    public function test_actual_terminal_query_callbacks_cannot_authorize_any_write_replay_read_or_no_op(string $operation, string $withdrawal): void
    {
        $f = F::plan() + CustomerFixtures::account();
        $ledger = app(CreditLedger::class);
        $plans = app(MembershipPlans::class);
        if (! in_array($operation, ['grant', 'plan-create', 'plan-apply', 'plan-noop', 'plan-review'], true)) {
            $f['grant'] = $ledger->grantSynthetic($f['version'], $f['account'], 'synthetic:query_baseline_award', $f['operator']);
        }
        if (in_array($operation, ['consume', 'reserve-replay'], true)) {
            $f['reservation'] = $ledger->reserve($f['grant']['bucket_id'], 1, 'synthetic:query_reserved', 'reserved', $f['principal'], $f['user']);
        }
        if (in_array($operation, ['plan-apply', 'plan-noop'], true)) {
            $f['review'] = $plans->reviewRevision($f['plan'], F::data($operation === 'plan-noop' ? [] : ['allowance' => 7]), $f['operator']);
        }
        $before = $this->rows();
        $write = in_array($operation, ['grant', 'plan-create', 'plan-apply', 'reserve', 'consume'], true);
        $planOperation = str_starts_with($operation, 'plan-');
        $armed = ! $write;
        $fired = false;
        $ordered = 0;
        AuditEvent::created(function () use (&$armed): void {
            $armed = true;
        });
        DB::listen(function ($query) use (&$armed, &$fired, &$ordered, $write, $planOperation, $withdrawal, $f): void {
            if (! $armed || $fired || ! str_contains($query->sql, 'order by')
                || ! str_contains($query->sql, $planOperation ? 'membership_plan_versions' : 'membership_credit_events')
                || ! str_contains($query->sql, $planOperation ? 'number' : 'sequence')) {
                return;
            }
            if (++$ordered !== ($write ? 1 : 2)) {
                return;
            }
            $fired = true;
            $pdo = DB::connection()->getPdo();
            if ($withdrawal === 'account') {
                $statement = $pdo->prepare('UPDATE customer_accounts SET active = 0, access_version = access_version + 1 WHERE id = ?');
                $statement->execute([$f['account']->id]);
            } else {
                [$field, $value, $id] = match ($withdrawal) {
                    'role' => ['is_admin', 0, $f['operator']->id],
                    'email' => ['email_verified_at', null, $f['operator']->id],
                    'mfa' => ['app_authentication_secret', null, $f['operator']->id],
                    'password' => ['password', 'SYNTHETIC_REVOKED_CREDENTIAL', $f['user']->id],
                    'customer-role' => ['is_admin', 1, $f['user']->id],
                };
                $statement = $pdo->prepare("UPDATE users SET $field = ? WHERE id = ?");
                $statement->execute([$value, $id]);
            }
        });
        $refused = false;
        try {
            match ($operation) {
                'grant' => $ledger->grantSynthetic($f['version'], $f['account'], 'synthetic:query_new_award', $f['operator']),
                'grant-replay' => $ledger->grantSynthetic($f['version'], $f['account'], 'synthetic:query_baseline_award', $f['operator']),
                'reserve', 'reserve-replay' => $ledger->reserve($f['grant']['bucket_id'], 1, 'synthetic:query_reserved', 'reserved', $f['principal'], $f['user']),
                'consume' => $ledger->consume($f['reservation']['event_id'], 'consumed', $f['principal'], $f['user']),
                'read' => $ledger->read($f['grant']['bucket_id'], $f['principal'], $f['user']),
                'plan-create' => $plans->createDraft(F::data(), $f['operator']),
                'plan-apply', 'plan-noop' => $plans->applyReviewedRevision($f['review'], $f['operator']),
                'plan-review' => $plans->reviewRevision($f['plan'], F::data(['allowance' => 7]), $f['operator']),
            };
        } catch (AuthorizationException|ValidationException) {
            $refused = true;
        }
        $this->assertTrue($fired, 'The genuine terminal QueryExecuted callback must run.');
        $this->assertTrue($refused, 'Current authority must refuse after this callback.');
        $this->assertSame($before, $this->rows(), 'Every new effect and callback mutation must roll back; replay/read adds nothing.');
        $this->assertSame(0, DB::transactionLevel());
    }

    public static function rangeCases(): array
    {
        return ['event range' => ['event'], 'audit range' => ['audit']];
    }

    #[DataProvider('rangeCases')]
    public function test_current_primary_ranges_refuse_a_post_query_insert_that_was_absent_from_the_fetched_rows(string $range): void
    {
        $f = F::plan() + CustomerFixtures::account();
        $before = $this->rows();
        $armed = false;
        $fired = false;
        $seen = 0;
        AuditEvent::created(function () use (&$armed): void {
            $armed = true;
        });
        DB::listen(function ($query) use (&$armed, &$fired, &$seen, $range, $f): void {
            $ordered = str_contains($query->sql, 'order by');
            $match = $range === 'event' ? str_contains($query->sql, 'membership_credit_events') && str_contains($query->sql, 'sequence')
                : str_contains($query->sql, 'audit_events') && preg_match('/select \* from/i', $query->sql);
            if (! $armed || $fired || ! $ordered || ! $match || ++$seen !== ($range === 'event' ? 1 : 2)) {
                return;
            }
            $fired = true;
            $pdo = DB::connection()->getPdo();
            $grant = $pdo->query('SELECT * FROM membership_credit_events ORDER BY sequence')->fetch(PDO::FETCH_ASSOC);
            if ($range === 'event') {
                $row = $grant;
                unset($row['id']);
                $row['sequence'] = 2;
                $row['kind'] = 'reserve';
                $row['amount'] = 1;
                $row['resource_hash'] = hash('sha256', 'synthetic:post_query_resource');
                $row['key_hash'] = hash('sha256', 'post_query_key');
                $row['request_hash'] = hash('sha256', 'post_query_request');
                $row['previous_event_id'] = $grant['id'];
                $row['previous_hash'] = $grant['event_hash'];
                $row['before_balance'] = $grant['after_balance'];
                $row['after_balance'] = json_encode(['available' => 2, 'reserved' => 1, 'consumed' => 0, 'expired' => 0], JSON_THROW_ON_ERROR);
                $row['event_hash'] = hash('sha256', 'post_query_event');
                $row['actor_id'] = $f['user']->id;
                $statement = $pdo->prepare('INSERT INTO membership_credit_events ('.implode(',', array_keys($row)).') VALUES ('.implode(',', array_fill(0, count($row), '?')).')');
                $statement->execute(array_values($row));
            } else {
                $statement = $pdo->prepare('INSERT INTO audit_events (actor_id, action, subject_type, subject_id, context, created_at) VALUES (?, ?, ?, ?, ?, ?)');
                $statement->execute([$f['operator']->id, 'membership.test_credit.grant', MembershipCreditBucket::class,
                    $grant['membership_credit_bucket_id'], '{"synthetic":"unbound_extra_audit"}', $grant['created_at']]);
            }
        });
        $refused = false;
        try {
            app(CreditLedger::class)->grantSynthetic($f['version'], $f['account'], 'synthetic:post_query_award', $f['operator']);
        } catch (AuthorizationException|ValidationException) {
            $refused = true;
        }
        $this->assertTrue($fired);
        $this->assertTrue($refused);
        $this->assertSame($before, $this->rows());
        $this->assertSame(0, DB::transactionLevel());
    }

    private function rows(): array
    {
        return array_map(fn ($table) => DB::table($table)->orderBy('id')->get()->toJson(),
            ['users', 'customer_accounts', 'membership_plans', 'membership_plan_versions', 'membership_credit_buckets', 'membership_credit_events', 'audit_events']);
    }
}
