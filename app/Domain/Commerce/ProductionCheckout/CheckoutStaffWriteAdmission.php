<?php

namespace App\Domain\Commerce\ProductionCheckout;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use ReflectionProperty;

/**
 * Sealed commit admission for NEW staff-authored exemption records (Codex P2 r4208264416, r4208109348).
 *
 * Retains the exact raw users row of the acting staff member (is_admin, email_verified_at, MFA
 * enrollment columns) with its audit history, as read by PacketAuthority/StaffProof, plus the fixed
 * plan rows behind the write. Gate and MFA providers are callbacks and run only before capture; at
 * commit a withdrawn role or MFA enrollment is a changed raw row, so the whole original physical
 * transaction rolls back.
 */
final class CheckoutStaffWriteAdmission implements CheckoutCommitAdmission
{
    private function __construct(
        private readonly CheckoutRawPlans $plans,
        private readonly Container $container,
        private readonly Repository $configuration,
        private readonly array $aliases,
        private readonly int $ownerId,
    ) {}

    /** Last step of ApproveExemptionAuthority::approve() after a NEW authority insert. */
    public static function authority(Records $rows, User $owner, array $staff, array $current, array $policy, array $record, string $requestKey): self
    {
        [$frame, $plans] = self::open($rows, $owner, $staff, [$policy['effective_until']]);
        $plans->history($current['raw']);
        $plans->row('authority', $record);
        $plans->selector('authority', 'owner_user_id = ? AND request_key = ?', [$owner->id, $requestKey], 2, [$record]);

        return self::seal($frame, $plans, $owner->id);
    }

    /** Last step of TaxExemptions::qualify() after a NEW basis insert. */
    public static function basis(Records $rows, User $qualifier, array $staff, User $buyer, array $identity, array $current,
        array $selection, array $authority, array $policy, array $attestation, array $record, string $requestKey): self
    {
        [$frame, $plans] = self::open($rows, $qualifier, $staff, [$policy['effective_until'], $attestation['effective_until']]);
        $plans->actor($buyer);
        $plans->identity($identity);
        $plans->selection($selection);
        $plans->history($current['raw']);
        $plans->row('authority', $authority);
        $plans->row('basis', $record);
        $plans->selector('basis', 'created_by = ? AND request_key = ?', [$qualifier->id, $requestKey], 2, [$record]);

        return self::seal($frame, $plans, $authority['owner_user_id']);
    }

    public function belongsTo(CheckoutCommandFrame $frame): bool
    {
        return $this->plans->belongsTo($frame);
    }

    public function proveCurrent(): void
    {
        $this->plans->proveCurrent();
    }

    /** Direct retained configuration items only: no config resolver, gate, MFA provider or model callback. */
    public function proveFresh(): void
    {
        $instances = (new ReflectionProperty(Container::class, 'instances'))->getValue($this->container);
        $aliases = (new ReflectionProperty(Container::class, 'aliases'))->getValue($this->container);
        $items = (new ReflectionProperty(Repository::class, 'items'))->getValue($this->configuration);
        $policy = is_array($items) ? ($items['production_checkout'] ?? null) : null;
        $owners = is_array($policy) ? ($policy['exemption_policy_owner_ids'] ?? null) : null;
        CheckoutException::require(is_array($instances) && is_array($items) && is_array($policy) && is_array($owners)
            && Container::getInstance() === $this->container
            && ($instances['config'] ?? null) === $this->configuration && $aliases === $this->aliases
            && ($policy['exemption_authoring_enabled'] ?? null) === true && in_array($this->ownerId, $owners, true), 'authority', 403);
    }

    /** @return array{0: CheckoutCommandFrame, 1: CheckoutRawPlans} */
    private static function open(Records $rows, User $actor, array $staff, array $until): array
    {
        $frame = $rows->commandFrame();
        $frame->proveAnchor();
        // Authenticated temporal bounds are converted once against the ORIGINAL command budget, never at commit.
        $expires = min(array_map(fn (string $value): float => (float) CarbonImmutable::parse($value, 'UTC')->format('U.u'), $until));
        $remaining = $expires - (float) CarbonImmutable::now('UTC')->format('U.u');
        CheckoutException::require($remaining > 0, 'expired');
        $frame->capDeadline(hrtime(true) + (int) floor($remaining * 1_000_000_000));
        $plans = new CheckoutRawPlans($frame);
        $plans->actor($actor);
        $plans->staff($actor->id, $staff);

        return [$frame, $plans];
    }

    private static function seal(CheckoutCommandFrame $frame, CheckoutRawPlans $plans, int $ownerId): self
    {
        $configuration = config();
        CheckoutException::require($configuration instanceof Repository && $configuration::class === Repository::class, 'authority', 403);
        $container = Container::getInstance();
        $aliases = (new ReflectionProperty(Container::class, 'aliases'))->getValue($container);
        $admission = new self($plans, $container, $configuration, $aliases, $ownerId);
        // No resolver, decryptor, gate, MFA provider or renderer follows this original raw proof.
        $admission->proveCurrent();
        $admission->proveFresh();
        $frame->register($admission);

        return $admission;
    }

    public function __serialize(): never
    {
        throw new \LogicException('Checkout staff write admission is an internal server capability.');
    }

    public function __debugInfo(): array
    {
        return ['authority' => 'original_checkout_new_staff_write_admission'];
    }
}
