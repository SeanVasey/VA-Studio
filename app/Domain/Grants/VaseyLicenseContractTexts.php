<?php

namespace App\Domain\Grants;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Customers\DataRights\PaidGrantExportSection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Contract increment 5, payload growth: the large immutable parts of a `vasey-license-pdf-v2` selection (the contract
 * template and the renderer profile) are stored once each in `vasey_license_contract_texts`, addressed by kind and the
 * SHA-256 of their bytes, and a grant origin keeps only the hashes ({@see VaseyLicenseIssuance}). Every v2 line of a
 * license version shares one template row, so an origin stays about as large as a gate-off one and the data export's
 * stored-payload budget ({@see PaidGrantExportSection}) is unaffected.
 *
 * Rows are encrypted at rest with the application key, as the origins are, and immutable (triggers refuse UPDATE and
 * DELETE). They hold license text shared by every buyer of a version, never customer data, so the data export never
 * reads them and deletion neither erases nor lists them. Every read is verified against the hash it is addressed by, and
 * {@see resolve()} tells a permanent integrity failure from a failure a retry may fix: a row that decrypts to bytes other
 * than its address (changed or planted), or a malformed address, refuses `profile_changed`; no row at the address, or a
 * row that does not decrypt (tampered, or a key this process lacks: the two cannot be told apart), refuses
 * `contract_text_unavailable`; a database error propagates. Nothing else is rendered in any of these cases.
 */
final class VaseyLicenseContractTexts
{
    public const TABLE = 'vasey_license_contract_texts';

    /** Plaintext byte bounds per kind: the selection's template bound, and the v2 manifest (at most 64 KiB) with its metadata. */
    public const KINDS = ['template' => VaseyLicenseIssuance::TEMPLATE_BYTES, 'renderer_profile' => 131072];

    /** Stored ciphertext bound: the encrypted envelope of the largest plaintext (about 1.78 times its size), with margin. */
    public const CIPHERTEXT_BYTES = 1048576;

    /**
     * Store the bytes under their hash unless that address is already stored, in the caller's transaction, and read back
     * what is stored there. True only when the stored row decrypts to exactly these bytes; a row with other content under
     * the address (planted or damaged) gives false, so the caller keeps its existing profile. Database errors propagate:
     * inside the origin transaction they must abort it, never be swallowed.
     */
    public static function retain(string $kind, string $bytes): bool
    {
        if (! isset(self::KINDS[$kind]) || $bytes === '' || strlen($bytes) > self::KINDS[$kind]) {
            return false;
        }
        $sha256 = hash('sha256', $bytes);
        DB::table(self::TABLE)->insertOrIgnore(['kind' => $kind, 'sha256' => $sha256, 'payload' => Crypt::encryptString($bytes),
            'created_at' => now()->utc()->format('Y-m-d H:i:s')]);
        // A locking read also sees a row another transaction committed after this one's snapshot (MySQL REPEATABLE READ).
        $payload = DB::table(self::TABLE)->where('kind', $kind)->where('sha256', $sha256)->sharedLock()->value('payload');
        try {
            return self::open($kind, $sha256, $payload) === $bytes;
        } catch (ContractIssuanceException) {
            return false;
        }
    }

    /**
     * The bytes stored under the address, verified against it. Refuses (a {@see ContractIssuanceException}) with
     * `profile_changed` for an integrity failure no retry can fix (a malformed address, or a stored row that decrypts to
     * bytes other than the address), and with `contract_text_unavailable` when no row is stored there or the stored row
     * cannot be decrypted with the keys this process holds. A database error propagates unchanged; callers treat it like
     * an absence.
     */
    public static function resolve(string $kind, string $sha256): string
    {
        if (! isset(self::KINDS[$kind]) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new ContractIssuanceException('profile_changed');
        }
        $payload = DB::table(self::TABLE)->where('kind', $kind)->where('sha256', $sha256)->value('payload');
        if ($payload === null) {
            throw new ContractIssuanceException('contract_text_unavailable');
        }

        return self::open($kind, $sha256, $payload);
    }

    /**
     * A row that cannot be opened (an out-of-bound or non-string payload, or one that does not decrypt) refuses
     * `contract_text_unavailable`: the ciphertext is authenticated, so a failed decrypt cannot tell tampering from a key
     * this process lacks (a worker during an `APP_KEY` rotation, a restore paired with another key), and a retry with the
     * right key resolves it. Only bytes that decrypt but differ from their address refuse `profile_changed`.
     */
    private static function open(string $kind, string $sha256, mixed $payload): string
    {
        try {
            $bytes = is_string($payload) && strlen($payload) <= self::CIPHERTEXT_BYTES ? Crypt::decryptString($payload) : null;
        } catch (Throwable) {
            $bytes = null;
        }
        if (! is_string($bytes)) {
            throw new ContractIssuanceException('contract_text_unavailable');
        }
        if ($bytes === '' || strlen($bytes) > self::KINDS[$kind] || ! hash_equals($sha256, hash('sha256', $bytes))) {
            throw new ContractIssuanceException('profile_changed');
        }

        return $bytes;
    }
}
