<?php

namespace Tests\Feature;

use App\Domain\Services\Projects\ServiceProjectInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FinalizationDatabaseMigrations;
use Tests\Support\ServiceProjectFixtures as F;
use Tests\TestCase;

/**
 * FP-087 pin (BL-64 phase 1): "no completion before required payment" holds today because the service-project journey has
 * no command that completes a project, collects or records a payment, or authorizes delivery, and the projection says so
 * on every read. This test pins that absence. A phase that adds a payment or completion path must replace it with the
 * real gate (a completion command refused until durable verified payment) and not simply delete it.
 *
 * BL-70 phase 1 adds default-off Stripe test-mode invoicing (docs/service-invoicing.md). It is deliberately not a project command:
 * an invoice is issued by a separate staff service and a payment is recorded only by re-reading a paid invoice, so this pin still
 * holds. With no verified service payment the projection still reads not_collected; ServiceInvoicingTest covers the paid states.
 */
class ServiceProjectPaymentGateTest extends TestCase
{
    use FinalizationDatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        app()->forgetInstance('encrypter');
    }

    public static function forbidden(): array
    {
        $actions = ['complete', 'mark_complete', 'mark_paid', 'record_payment', 'collect_deposit', 'pay_deposit', 'authorize_delivery', 'deliver', 'release_deliverable', 'refund'];

        return array_combine($actions, array_map(fn (string $action): array => [$action], $actions));
    }

    #[DataProvider('forbidden')]
    public function test_no_buyer_or_staff_command_can_complete_charge_or_deliver(string $action): void
    {
        foreach ([true, false] as $staff) {
            try {
                ServiceProjectInput::command(['requestKey' => '2f9d2e0e-3d0e-4c5a-9a3e-1b2c3d4e5f60', 'expectedVersion' => 1, 'action' => $action, 'reason' => 'x', 'milestoneId' => 'mix-review'], $staff);
                $this->fail('The '.($staff ? 'staff' : 'buyer').' command "'.$action.'" must not exist.');
            } catch (ValidationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_reason_only_commands_are_exactly_the_reviewed_non_payment_ones(): void
    {
        $candidates = ['cancel', 'withdraw', 'request_cancellation', 'complete', 'mark_paid', 'pay', 'checkout', 'invoice', 'close', 'finish', 'archive', 'reopen', 'approve', 'accept'];
        $accepted = function (bool $staff) use ($candidates): array {
            return array_values(array_filter($candidates, function (string $action) use ($staff): bool {
                try {
                    ServiceProjectInput::command(['requestKey' => '2f9d2e0e-3d0e-4c5a-9a3e-1b2c3d4e5f60', 'expectedVersion' => 1, 'action' => $action, 'reason' => 'x'], $staff);

                    return true;
                } catch (ValidationException) {
                    return false;
                }
            }));
        };
        $this->assertSame(['cancel'], $accepted(true));
        $this->assertSame(['withdraw', 'request_cancellation'], $accepted(false));
    }

    public function test_after_every_reachable_step_the_project_is_unpaid_undelivered_and_never_completed(): void
    {
        $fixture = F::setup();
        $project = F::accept($fixture);
        $seen = [$project];
        $id = $project['id'];
        foreach ([['staff', 'begin_milestone'], ['staff', 'ready_milestone'], ['buyer', 'approve_milestone']] as [$who, $action]) {
            $command = F::command($project, $action, ['milestoneId' => 'mix-review', 'reason' => 'Synthetic scope step']);
            $project = $who === 'staff'
                ? $fixture['journey']->staffCommand($id, $command, $fixture['operator'])['project']
                : $fixture['journey']->customerCommand($id, $command, $fixture['customer']['principal'], $fixture['customer']['user'])['project'];
            $seen[] = $project;
        }
        $this->assertSame('scope_reviewed', $project['status']);
        foreach ($seen as $state) {
            $this->assertSame('not_collected', $state['paymentState']);
            $this->assertFalse($state['deliveryAuthorized']);
            $this->assertNotContains($state['status'], ['completed', 'complete', 'paid', 'delivered']);
        }
        foreach (['orders', 'pending_entitlements', 'verified_payments', 'payment_observations'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
    }
}
