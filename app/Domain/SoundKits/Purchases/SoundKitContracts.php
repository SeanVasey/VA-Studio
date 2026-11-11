<?php

namespace App\Domain\SoundKits\Purchases;

use App\Domain\Contracts\ContractIssuanceException;
use App\Domain\Contracts\Production\ContractBindingException;
use App\Domain\Contracts\Production\SoundKitLicenseBinder;
use App\Domain\Contracts\Production\VaseyKitLicensePdfRenderer;
use App\Domain\Contracts\Production\VaseyKitLicenseProfile;
use App\Domain\Contracts\Production\VaseyLicenseSignature;
use App\Domain\Media\MediaFailure;
use App\Domain\Media\PrivateMediaFiles;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\SoundKits\Purchases\Models\SoundKitContract;
use App\Domain\SoundKits\Purchases\Models\SoundKitGrant;
use App\Domain\SoundKits\Purchases\Models\SoundKitOrder;
use App\Domain\SoundKits\Purchases\Models\SoundKitPayment;
use App\Support\Audit\AuditEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Issues the kit license contract of a grant (BL-13): the published `sound-kit` license version's frozen text, rendered by the
 * `vasey-kit-license-pdf-v1` profile ({@see VaseyKitLicensePdfRenderer}, the shared visual system and Sean's pinned signature).
 * Only the six purchase-details placeholders are filled, from the immutable order snapshot, the verified payment and the
 * configured licensor name (`VASEY_KIT_LICENSE_LICENSOR_NAME`); the text itself is never altered.
 *
 * The original is written once to the private disk (never a public path) and recorded as immutable evidence: SHA-256, size,
 * pages, the hash of the drawn text, the effective profile hash (profile plus signature pin) and the input hash. Rendering is
 * deterministic, so a concurrent second issue produces the same bytes at the same address and records nothing new. Every read
 * re-hashes the file against that record.
 */
final class SoundKitContracts
{
    public const DIRECTORY = 'sound-kit-contracts';

    public function issue(SoundKitGrant $grant): SoundKitContract
    {
        app(SoundKitPurchasePolicy::class)->require();
        if (DB::transactionLevel() !== 0) {
            throw new ContractIssuanceException('unsupported_input');
        }
        $existing = SoundKitContract::query()->where('sound_kit_grant_id', $grant->id)->first();
        if ($existing) {
            $this->bytes($existing);

            return $existing;
        }
        $order = app(SoundKitOrders::class)->verified(SoundKitOrder::query()->findOrFail($grant->sound_kit_order_id));
        $payment = SoundKitPayment::query()->findOrFail($grant->sound_kit_payment_id);
        $snapshot = $order->snapshot;
        $license = LicenseVersion::query()->findOrFail($grant->license_version_id);
        // The license is the exact published version the buyer paid for: same id, review hash and text bytes.
        if ($grant->snapshot_hash !== $order->snapshot_hash || $license->id !== $snapshot['license']['version_id']
            || $license->submission_hash !== $snapshot['license']['submission_hash']
            || ! hash_equals($snapshot['license']['source_sha256'], hash('sha256', (string) $license->authored_source))
            || $payment->sound_kit_order_id !== $order->id) {
            throw new ContractIssuanceException('profile_changed');
        }
        $renderer = app(VaseyKitLicensePdfRenderer::class);
        try {
            $values = SoundKitLicenseBinder::bind([
                'kit_name' => $snapshot['product']['title'],
                'purchased_at' => $payment->verified_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'fee_minor' => $payment->amount_minor,
                'currency' => $payment->currency,
                'licensee_name' => app(SoundKitOrders::class)->licensee($order),
                'order_reference' => $order->public_id,
            ], SoundKitLicenseBinder::licensorFromConfig(), $renderer->coverage());
            $document = $renderer->document((string) $license->authored_source, $values);
        } catch (ContractBindingException) {
            throw new ContractIssuanceException('unsupported_input');
        }
        $rendered = $renderer->render($document, VaseyKitLicenseProfile::current(), VaseyLicenseSignature::fromConfig());
        $relative = self::DIRECTORY.'/'.$grant->public_id.'/'.$rendered->sha256.'.pdf';
        $this->write($relative, $rendered->pdfBytes, $rendered->sha256);

        $contract = DB::transaction(function () use ($grant, $relative, $rendered, $document): SoundKitContract {
            SoundKitGrant::query()->whereKey($grant->id)->lockForUpdate()->firstOrFail();
            $existing = SoundKitContract::query()->where('sound_kit_grant_id', $grant->id)->first();
            if ($existing) {
                return $existing;
            }
            $contract = SoundKitContract::create(['public_id' => (string) Str::uuid(), 'sound_kit_grant_id' => $grant->id, 'storage_path' => $relative,
                'sha256' => $rendered->sha256, 'size_bytes' => $rendered->sizeBytes, 'pages' => $rendered->pageCount,
                'text_sha256' => $rendered->textDigest, 'profile_sha256' => $rendered->profileHash, 'input_sha256' => $document->inputHash,
                'issued_at' => now()->toImmutable()->utc()->startOfSecond()]);
            AuditEvent::recordAttributed('sound_kit.test_contract.original_recorded', $contract, ['grant_public_id' => $grant->public_id,
                'sha256' => $rendered->sha256, 'profile' => VaseyKitLicenseProfile::VERSION, 'test_only' => true], null);

            return $contract;
        }, 5);
        $this->bytes($contract);

        return $contract;
    }

    /** The recorded original's bytes, re-hashed against the record; a changed, missing or linked file refuses. */
    public function bytes(SoundKitContract $contract): string
    {
        try {
            $path = app(PrivateMediaFiles::class)->resolve($contract->storage_path);
        } catch (MediaFailure) {
            throw new ContractIssuanceException('original_unavailable');
        }
        $bytes = @file_get_contents($path);
        if (! is_string($bytes) || strlen($bytes) !== $contract->size_bytes || ! hash_equals($contract->sha256, hash('sha256', $bytes))) {
            throw new ContractIssuanceException('original_changed');
        }

        return $bytes;
    }

    private function write(string $relative, string $bytes, string $sha256): void
    {
        $root = app(PrivateMediaFiles::class)->root();
        $directory = $root.'/'.dirname($relative);
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new ContractIssuanceException('render_failed');
        }
        $path = $root.'/'.$relative;
        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            // Deterministic rendering: an earlier or concurrent issue wrote these exact bytes at this exact address.
            $current = is_file($path) && ! is_link($path) ? @hash_file('sha256', $path) : false;
            if ($current !== $sha256) {
                throw new ContractIssuanceException('render_failed');
            }

            return;
        }
        try {
            if (@fwrite($handle, $bytes) !== strlen($bytes) || ! @fflush($handle)) {
                throw new ContractIssuanceException('render_failed');
            }
        } finally {
            fclose($handle);
        }
        @chmod($path, 0400);
    }
}
