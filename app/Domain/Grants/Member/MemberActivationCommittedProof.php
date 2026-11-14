<?php

namespace App\Domain\Grants\Member;

use App\Domain\Customers\ProductionIdentity\IdentityCommittedFrame;
use WeakMap;

/** Different one-use evidence registration; no ready token or closed Rows proof is reused. */
final class MemberActivationCommittedProof implements \JsonSerializable
{
    private static ?WeakMap $issued = null;

    private function __construct() {}

    public static function register(MemberOriginalSourceCapture $issuer, MemberActivationInspection $inspection,
        MemberActivationObserver $observer): self
    {
        $issuer->admitActivationCommittedRegistration($inspection, $observer);
        $proof = new self;
        self::$issued ??= new WeakMap;
        self::$issued[$proof] = ['inspection' => $inspection, 'observer' => $observer, 'frame' => null, 'consumed' => false];

        return $proof;
    }

    public function prove(IdentityCommittedFrame $frame): void
    {
        $entry = self::$issued[$this] ?? null;
        MemberGrantException::require(is_array($entry) && $entry['frame'] === null && ! $entry['consumed'], 'foreign_committed_proof');
        $entry['observer']->requireProof();
        $entry['frame'] = $frame;
        self::$issued[$this] = $entry;
        try {
            $entry['inspection']->proveCommitted($this, $frame);
        } finally {
            unset(self::$issued[$this]);
        }
    }

    public static function consume(self $proof, MemberActivationInspection $inspection, IdentityCommittedFrame $frame): void
    {
        $entry = self::$issued[$proof] ?? null;
        MemberGrantException::require(is_array($entry) && $entry['inspection'] === $inspection && $entry['frame'] === $frame
            && ! $entry['consumed'], 'foreign_committed_proof');
        $entry['consumed'] = true;
        self::$issued[$proof] = $entry;
    }

    public function __serialize(): never
    {
        throw new MemberGrantException('private_committed_proof');
    }

    public function __unserialize(array $data): void
    {
        throw new MemberGrantException('private_committed_proof');
    }

    public function __clone(): never
    {
        throw new MemberGrantException('private_committed_proof');
    }

    public function jsonSerialize(): never
    {
        throw new MemberGrantException('private_committed_proof');
    }

    public function __debugInfo(): array
    {
        return ['member_original_committed_proof' => true];
    }
}
