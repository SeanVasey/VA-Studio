<?php

namespace App\Http\Membership;

use App\Domain\Memberships\Production\MembershipCustomerIndex;
use App\Domain\Memberships\Production\MembershipCustomerRead;
use App\Domain\Memberships\Production\MembershipException;
use App\Domain\Memberships\Production\MembershipHttpBinding;
use App\Domain\Memberships\Production\MembershipReservationOperations;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Customer member HTTP over the C4d domain (C4e): the bounded period index and period-addressed reserve, release and
 * expire. Every business rule stays in the domain; this class maps the request-bound identity capsule and a bounded
 * body onto it, proves the capsule again around each call, and maps refusals through MembershipPrivacy (F7, F8).
 *
 * The index body is built first and released only after the capsule and `MembershipCustomerRead::proveBeforeBytes()`
 * re-prove the owner, gates and the complete ledger read under the original deadline. A writer's committed receipt is
 * released only after the capsule and the mount are proved again; if that fails the write is durable, so the response
 * is "submitted, refresh", never a refusal. The activation family (review, confirm, activate, readActivated) is not
 * mounted: it needs the member render profile r2, its own pre-bytes hooks and a separate owner decision.
 */
final class MembershipController
{
    private const REQUEST_KEY = '/\A[A-Za-z0-9_-]{16,128}\z/D';

    private const OFFER = '/\A[1-9][0-9]{0,15}\z/D';

    public function index(Request $request): Response
    {
        try {
            MembershipException::require(MembershipHttpMount::admitted(), 'not_mounted');
            $identity = MembershipHttpBinding::forRequest($request);
            MembershipException::require($this->body($request) === [], 'invalid_input');
            $read = MembershipCustomerRead::begin();
            $index = (new MembershipCustomerIndex)->index($identity->principal(), $identity->actor(), $read);
            $response = MembershipPrivacy::json(['index' => self::indexBody($index)]);
        } catch (Throwable $error) {
            return MembershipPrivacy::refusal($error);
        }
        try {
            // Pre-bytes (operations step 6, review C2): the prebuilt body leaves only after a fresh owned re-read, the
            // capsule and the mount. Any refusal withholds the body behind the one not-found response.
            $identity->proveCurrent();
            $read->proveBeforeBytes();
            MembershipException::require(MembershipHttpMount::admitted(), 'not_mounted');
            $identity->proveCurrent();

            return $response;
        } catch (Throwable) {
            return MembershipPrivacy::notFound();
        }
    }

    public function reserve(string $period, Request $request): Response
    {
        return $this->write($request, function (MembershipHttpBinding $identity, array $body) use ($period): array {
            return (new MembershipReservationOperations)->reserveInPeriod($period, $body['offerId'], $body['expectedRevision'],
                $body['requestKey'], $identity->principal(), $identity->actor());
        }, function (array $body): bool {
            // Exactly these three keys, in any order (JSON object members are unordered): none missing, none extra.
            $keys = array_keys($body);
            sort($keys, SORT_STRING);

            return $keys === ['expectedRevision', 'offerId', 'requestKey']
                && is_string($body['offerId']) && preg_match(self::OFFER, $body['offerId']) === 1
                && is_int($body['expectedRevision']) && $body['expectedRevision'] > 0
                && is_string($body['requestKey']) && preg_match(self::REQUEST_KEY, $body['requestKey']) === 1;
        });
    }

    public function release(string $period, string $redemption, Request $request): Response
    {
        return $this->write($request, fn (MembershipHttpBinding $identity): array => (new MembershipReservationOperations)
            ->releaseInPeriod($period, $redemption, $identity->principal(), $identity->actor()), fn (array $body): bool => $body === []);
    }

    public function expire(string $period, Request $request): Response
    {
        return $this->write($request, fn (MembershipHttpBinding $identity): array => (new MembershipReservationOperations)
            ->expireInPeriod($period, $identity->principal(), $identity->actor()), fn (array $body): bool => $body === []);
    }

    /**
     * @param  Closure(MembershipHttpBinding, array): array  $operation
     * @param  Closure(array): bool  $valid
     */
    private function write(Request $request, Closure $operation, Closure $valid): Response
    {
        try {
            MembershipException::require(MembershipHttpMount::admitted(), 'not_mounted');
            $identity = MembershipHttpBinding::forRequest($request);
            $body = $this->body($request);
            if (! $valid($body)) {
                return MembershipPrivacy::error(422);
            }
            $identity->proveCurrent();
        } catch (Throwable $error) {
            // Nothing was attempted yet: an ordinary refusal.
            return MembershipPrivacy::refusal($error);
        }
        try {
            $result = $operation($identity, $body);
        } catch (Throwable $error) {
            return MembershipPrivacy::refusal($error, true);
        }
        try {
            $response = MembershipPrivacy::json(['result' => self::receipt($result)]);
            // A committed receipt still passes the owner capsule and the mount before its bytes; a refusal here can
            // only hide a durable effect, so it is the fixed "submitted, refresh" response, never a retry prompt.
            $identity->proveCurrent();
            MembershipException::require(MembershipHttpMount::admitted(), 'not_mounted');

            return $response;
        } catch (Throwable) {
            return MembershipPrivacy::submitted();
        }
    }

    private const INDEX_KEYS = ['schemaVersion', 'periodLimit', 'hasMore', 'provenance', 'periods'];

    private const PERIOD_KEYS = ['id', 'planVersionId', 'periodStart', 'periodEnd', 'creditExpiresAt', 'allowance', 'balances',
        'applicableDefinition', 'reservations'];

    private const RESERVATION_KEYS = ['id', 'offerId', 'offerRevisionId', 'creditAmount', 'reservedAt', 'honorDeadline', 'status', 'closedAt'];

    private const BALANCE_KEYS = ['available', 'reserved', 'consumed', 'expired'];

    /**
     * Review N6: the index body is rebuilt from an exact key allowlist. A domain projection with any other key is
     * refused (the one not-found response), so a new domain field can never reach a customer unreviewed.
     */
    private static function indexBody(array $index): array
    {
        $exact = static function (mixed $value, array $keys): array {
            MembershipException::require(is_array($value) && array_keys($value) === $keys, 'tampered');

            return $value;
        };
        $index = $exact($index, self::INDEX_KEYS);
        MembershipException::require(array_is_list($index['periods']), 'tampered');
        $periods = [];
        foreach ($index['periods'] as $period) {
            $period = $exact($period, self::PERIOD_KEYS);
            MembershipException::require(array_is_list($period['reservations']), 'tampered');
            $reservations = [];
            foreach ($period['reservations'] as $reservation) {
                $reservations[] = $exact($reservation, self::RESERVATION_KEYS);
            }
            $definition = $period['applicableDefinition'] === null ? null : ['id' => $exact($period['applicableDefinition'], ['id'])['id']];
            $periods[] = [...$period, 'balances' => $exact($period['balances'], self::BALANCE_KEYS),
                'applicableDefinition' => $definition, 'reservations' => $reservations];
        }

        return [...$index, 'periods' => $periods];
    }

    /** Allowlisted receipt fields only: period and redemption ids, status, credit, honor deadline and balances. */
    private static function receipt(array $result): array
    {
        $balances = $result['balances'] ?? null;
        MembershipException::require(is_array($balances) && array_keys($balances) === ['available', 'reserved', 'consumed', 'expired']
            && count(array_filter($balances, 'is_int')) === 4 && is_string($result['period_id'] ?? null) && is_string($result['status'] ?? null), 'tampered');
        $receipt = ['periodId' => $result['period_id'], 'status' => $result['status'], 'balances' => $balances];
        if (array_key_exists('redemption_id', $result)) {
            $receipt['redemptionId'] = $result['redemption_id'];
        }
        if (array_key_exists('credit_amount', $result)) {
            $receipt['creditAmount'] = $result['credit_amount'];
            $receipt['honorDeadline'] = $result['honor_deadline'];
        }

        return $receipt;
    }

    private function body(Request $request): array
    {
        $body = $request->attributes->get(MembershipPrivacy::BODY);
        MembershipException::require(is_array($body), 'invalid_input');

        return $body;
    }
}
