<?php

namespace App\Domain\SoundKits\Products;

use App\Domain\Media\MediaFailure;
use App\Domain\Rights\Models\LicenseVersion;
use App\Domain\Rights\SoundKitLicenseAttachment;
use App\Domain\Rights\SoundKitLicenseKind;
use App\Domain\Rights\VerifiedLicense;
use App\Domain\SoundKits\Models\SoundKitDraft;
use App\Domain\SoundKits\Models\SoundKitRevision;
use App\Domain\SoundKits\SoundKitManifest;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The only writer of sound-kit products (BL-53, FP-007). Each command runs in one transaction that locks the acting staff user,
 * then the product, then the private kit and archive revision, and records one audit event. A product is a catalog record:
 * nothing here creates an order, payment, grant, entitlement or download, and nothing prices anything the staff did not enter.
 *
 * Publication mirrors the track gate. A kit is listed only when ALL of these hold, re-checked at every public read:
 * - it has a title and a public summary;
 * - the chosen archive revision is `ready` and still matches its pinned manifest hash, and at publication its retained scan
 *   evidence and physical archive bytes verify ({@see SoundKitManifest::verified});
 * - staff recorded rights clearance for exactly that revision (a provenance note alone approves nothing);
 * - it carries a currently published, effective, reviewed license version of the sound-kit kind (never a beat license);
 * - it has a positive integer USD price;
 * - staff approved the offer for exactly those terms (revision, license, price): changing any of them voids the approval.
 */
final class SoundKitProducts
{
    public const CURRENCY = 'USD';

    private const FIELDS = ['sound_kit_draft_id', 'title', 'slug', 'summary', 'revision_public_id', 'license_version_id', 'price_minor'];

    /** @param  array<string, mixed>  $data  exactly the editable fields */
    public function save(?SoundKitProduct $product, array $data, User $actor, ?int $expectedRevision = null): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $data, $actor, $expectedRevision): SoundKitProduct {
            $current = $this->lockActor($actor);
            if (array_diff(array_keys($data), self::FIELDS) !== [] || array_diff(self::FIELDS, array_keys($data)) !== []) {
                $this->reject('title', 'The kit product form is incomplete. Reload and try again.');
            }
            $locked = $product?->exists ? SoundKitProduct::query()->lockForUpdate()->find($product->getKey()) : new SoundKitProduct;
            if ($locked === null) {
                $this->reject('title', 'This kit product is no longer available. Reload and choose a current product.');
            }
            if ($locked->exists && $expectedRevision !== null && $expectedRevision !== $locked->revision) {
                $this->reject('title', 'This kit product changed after you opened it. Reload it and apply your edits again.');
            }
            $draft = $this->lockDraft($data['sound_kit_draft_id'], $locked);
            $after = $this->normalise($data, $locked, $draft);
            $before = $locked->exists ? $this->snapshot($locked) : null;
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => $before === null || $before[$field] !== $after[$field]));
            if ($changed === []) {
                return $locked;
            }
            $revisionChanged = $before === null || $before['revision_public_id'] !== $after['revision_public_id'];
            $termsChanged = $revisionChanged || $before['license_version_id'] !== $after['license_version_id'] || $before['price_minor'] !== $after['price_minor'];
            $revision = $after['revision_public_id'] === null ? null : $this->lockRevision($draft, $after['revision_public_id']);
            $attributes = ['sound_kit_draft_id' => $draft->id, 'title' => $after['title'], 'slug' => $after['slug'], 'summary' => $after['summary'],
                'sound_kit_revision_id' => $revision?->id, 'manifest_sha256' => $revision?->manifest_sha256,
                'license_version_id' => $after['license_version_id'], 'price_minor' => $after['price_minor'], 'currency' => self::CURRENCY,
                'revision' => $locked->exists ? $locked->revision + 1 : 1];
            if (! $locked->exists) {
                $attributes += ['public_id' => (string) Str::uuid(), 'created_by' => $current->id];
            }
            // Any change to the terms voids the approval, and a different archive voids the rights clearance; they are re-read
            // against the current terms at every public read as well, so a stale record can never list a kit.
            if ($termsChanged) {
                $attributes += ['offer_terms_hash' => null, 'offer_approved_at' => null, 'offer_approved_by' => null];
            }
            if ($revisionChanged) {
                $attributes += ['rights_cleared_revision_id' => null, 'rights_note' => null, 'rights_cleared_at' => null, 'rights_cleared_by' => null];
            }
            $locked->fill($attributes);
            if ($locked->is_published) {
                // Refuse before writing: a published kit must stay publishable. Unpublish first to change its terms.
                $this->requireReady($locked, verifyArchive: false);
            }
            $locked->save();
            AuditEvent::record('sound_kit_products.product.'.($before === null ? 'created' : 'updated'), $locked, [
                'schema_version' => 1, 'changed_fields' => $changed, 'published' => (bool) $locked->is_published, 'revision' => $locked->revision,
                'terms_voided' => $before !== null && $termsChanged,
                'before_hash' => $before === null ? null : CanonicalJson::hash($before), 'after_hash' => CanonicalJson::hash($after),
            ], $current->id);

            return $locked->refresh();
        });
    }

    /** Records that staff cleared the rights to sell exactly the chosen archive revision. */
    public function clearRights(SoundKitProduct $product, int $expectedRevision, mixed $note, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $note, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            if ($locked->sound_kit_revision_id === null) {
                $this->reject('revision_public_id', 'Choose an archive revision before clearing rights.');
            }
            $this->lockRevisionRow($locked);
            $clean = $this->note($note);
            $locked->forceFill(['rights_cleared_revision_id' => $locked->sound_kit_revision_id, 'rights_note' => $clean, 'rights_cleared_at' => now(),
                'rights_cleared_by' => $current->id, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('sound_kit_products.product.rights_cleared', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'archive_revision_id' => $locked->sound_kit_revision_id,
                'manifest_sha256' => $locked->manifest_sha256, 'note_hash' => CanonicalJson::hash(['note' => $clean]),
            ], $current->id);

            return $locked->refresh();
        });
    }

    /** Withdraws rights clearance. A published kit stops being listed at once; the staff record of the product is kept. */
    public function revokeRights(SoundKitProduct $product, int $expectedRevision, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            if ($locked->rights_cleared_revision_id === null) {
                $this->reject('rights', 'No rights clearance is recorded.');
            }
            $locked->forceFill(['rights_cleared_revision_id' => null, 'rights_note' => null, 'rights_cleared_at' => null, 'rights_cleared_by' => null,
                'revision' => $locked->revision + 1])->save();
            AuditEvent::record('sound_kit_products.product.rights_revoked', $locked, ['schema_version' => 1, 'revision' => $locked->revision, 'published' => (bool) $locked->is_published], $current->id);

            return $locked->refresh();
        });
    }

    /** Approves the offer for the exact revision, license and price now on the product. */
    public function approveOffer(SoundKitProduct $product, int $expectedRevision, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            $problems = array_intersect_key($this->problems($locked, verifyArchive: false, lock: true), array_flip(['revision_public_id', 'license_version_id', 'price_minor']));
            if ($problems !== []) {
                throw ValidationException::withMessages($problems);
            }
            $hash = $this->termsHash($locked);
            $locked->forceFill(['offer_terms_hash' => $hash, 'offer_approved_at' => now(), 'offer_approved_by' => $current->id, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('sound_kit_products.product.offer_approved', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'offer_terms_hash' => $hash, 'price_minor' => $locked->price_minor, 'currency' => $locked->currency,
                'license_version_id' => $locked->license_version_id, 'archive_revision_id' => $locked->sound_kit_revision_id,
            ], $current->id);

            return $locked->refresh();
        });
    }

    public function publish(SoundKitProduct $product, int $expectedRevision, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            if ($locked->is_published) {
                $this->reject('is_published', 'This kit is already published.');
            }
            $this->lockRevisionRow($locked);
            $this->requireReady($locked, verifyArchive: true);
            $locked->forceFill(['is_published' => true, 'published_at' => now(), 'published_by' => $current->id, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('sound_kit_products.product.published', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'content_hash' => CanonicalJson::hash($this->snapshot($locked)),
                'offer_terms_hash' => $locked->offer_terms_hash,
            ], $current->id);

            return $locked->refresh();
        });
    }

    public function unpublish(SoundKitProduct $product, int $expectedRevision, User $actor): SoundKitProduct
    {
        return DB::transaction(function () use ($product, $expectedRevision, $actor): SoundKitProduct {
            $current = $this->lockActor($actor);
            $locked = $this->lockProduct($product, $expectedRevision);
            if (! $locked->is_published) {
                $this->reject('is_published', 'This kit is not published.');
            }
            $locked->forceFill(['is_published' => false, 'revision' => $locked->revision + 1])->save();
            AuditEvent::record('sound_kit_products.product.unpublished', $locked, [
                'schema_version' => 1, 'revision' => $locked->revision, 'content_hash' => CanonicalJson::hash($this->snapshot($locked)),
            ], $current->id);

            return $locked->refresh();
        });
    }

    /**
     * What stops a product from being listed, keyed by form field. Empty means ready. Reads only and takes no locks, so it is
     * the public read-time check. It does not hash the archive bytes (a 200 MiB read per page view); publication does, and the
     * revision row is immutable once `ready`, so a pinned hash that still matches cannot have changed.
     *
     * @return array<string, string>
     */
    public function blockers(SoundKitProduct $product): array
    {
        return $this->problems($product, verifyArchive: false, lock: false);
    }

    /** True when the product is published and nothing blocks it. The only listing predicate. */
    public function listed(SoundKitProduct $product): bool
    {
        return $product->is_published && $this->blockers($product) === [];
    }

    /** The hash that binds an offer approval (and, later, a purchase snapshot) to the exact terms. */
    public function termsHash(SoundKitProduct $product): string
    {
        return CanonicalJson::hash(['schema' => 'sound-kit-offer-terms-v1', 'product' => $product->public_id,
            'archive_revision_id' => $product->sound_kit_revision_id, 'manifest_sha256' => $product->manifest_sha256,
            'license_version_id' => $product->license_version_id, 'price_minor' => $product->price_minor, 'currency' => $product->currency]);
    }

    private function requireReady(SoundKitProduct $product, bool $verifyArchive): void
    {
        $problems = $this->problems($product, $verifyArchive, lock: DB::transactionLevel() > 0);
        if ($problems !== []) {
            throw ValidationException::withMessages($problems);
        }
    }

    /** @return array<string, string> */
    private function problems(SoundKitProduct $product, bool $verifyArchive, bool $lock): array
    {
        $problems = [];
        if (trim((string) $product->title) === '') {
            $problems['title'] = 'Enter a title.';
        }
        if (trim((string) $product->summary) === '') {
            $problems['summary'] = 'Write the public summary before publishing.';
        }
        $revision = $product->sound_kit_revision_id === null ? null : SoundKitRevision::query()->find($product->sound_kit_revision_id);
        if ($revision === null) {
            $problems['revision_public_id'] = 'Choose a verified archive revision.';
        } elseif ($revision->sound_kit_draft_id !== $product->sound_kit_draft_id || $revision->status !== 'ready' || ! is_string($revision->manifest_sha256)
            || ! is_string($product->manifest_sha256) || ! hash_equals($product->manifest_sha256, $revision->manifest_sha256)) {
            $problems['revision_public_id'] = 'The chosen archive revision is not a verified revision of this kit. Choose another.';
        } elseif ($verifyArchive) {
            try {
                app(SoundKitManifest::class)->verified($revision);
            } catch (MediaFailure) {
                $problems['revision_public_id'] = 'The kit archive could not be verified as clean and intact (scan evidence or private bytes).';
            }
        }
        if ($revision === null || $product->rights_cleared_revision_id !== $revision->id || $product->rights_cleared_at === null || $product->rights_cleared_by === null) {
            $problems['rights'] = 'Record rights clearance for the chosen archive revision.';
        }
        $license = $product->license_version_id === null ? null : LicenseVersion::query()->with('template')->find($product->license_version_id);
        if ($license === null) {
            $problems['license_version_id'] = 'Choose a published sound-kit license version.';
        } elseif (! SoundKitLicenseKind::isKitType($license->template?->type)) {
            $problems['license_version_id'] = 'A sound kit can carry only a sound-kit license.';
        } elseif (! ($lock ? app(VerifiedLicense::class)->availableForPublication($license) : app(VerifiedLicense::class)->available($license))) {
            $problems['license_version_id'] = 'The license must be published, effective and have intact review evidence.';
        }
        if (! is_int($product->price_minor) || $product->price_minor < 1 || $product->price_minor > 2147483647 || $product->currency !== self::CURRENCY) {
            $problems['price_minor'] = 'Kits require a positive USD price in integer cents.';
        }
        if ($product->offer_terms_hash === null || $product->offer_approved_at === null || $product->offer_approved_by === null
            || ! hash_equals($product->offer_terms_hash, $this->termsHash($product))) {
            $problems['offer'] = 'Approve the offer for the current archive revision, license and price.';
        }

        return $problems;
    }

    private function lockProduct(SoundKitProduct $product, int $expectedRevision): SoundKitProduct
    {
        $locked = $product->exists ? SoundKitProduct::query()->lockForUpdate()->find($product->getKey()) : null;
        if ($locked === null) {
            $this->reject('title', 'This kit product is no longer available. Reload and choose a current product.');
        }
        if ($locked->revision !== $expectedRevision) {
            $this->reject('title', 'This kit product changed after you opened it. Reload it and review it again.');
        }

        return $locked;
    }

    private function lockDraft(mixed $id, SoundKitProduct $locked): SoundKitDraft
    {
        $id = is_string($id) && ctype_digit($id) ? (int) $id : $id;
        if (! is_int($id) || $id < 1 || ($locked->exists && $locked->sound_kit_draft_id !== $id)) {
            $this->reject('sound_kit_draft_id', $locked->exists ? 'The private kit of a product cannot change.' : 'Choose a private kit.');
        }
        $draft = SoundKitDraft::query()->lockForUpdate()->find($id);
        if ($draft === null) {
            $this->reject('sound_kit_draft_id', 'This private kit is no longer available. Reload and choose again.');
        }
        if (! $locked->exists && SoundKitProduct::query()->where('sound_kit_draft_id', $draft->id)->lockForUpdate()->exists()) {
            $this->reject('sound_kit_draft_id', 'This kit already has a product.');
        }

        return $draft;
    }

    private function lockRevision(SoundKitDraft $draft, string $publicId): SoundKitRevision
    {
        $revision = SoundKitRevision::query()->where('public_id', $publicId)->where('sound_kit_draft_id', $draft->id)->lockForUpdate()->first();
        if ($revision === null || $revision->status !== 'ready' || ! is_string($revision->manifest_sha256)) {
            $this->reject('revision_public_id', 'Choose a technically verified (ready) archive revision of this kit.');
        }

        return $revision;
    }

    private function lockRevisionRow(SoundKitProduct $product): void
    {
        if ($product->sound_kit_revision_id !== null) {
            SoundKitRevision::query()->whereKey($product->sound_kit_revision_id)->lockForUpdate()->first();
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(SoundKitProduct $product): array
    {
        return [
            'sound_kit_draft_id' => $product->sound_kit_draft_id, 'title' => $product->title, 'slug' => $product->slug, 'summary' => $product->summary,
            'revision_public_id' => $product->sound_kit_revision_id === null ? null : SoundKitRevision::query()->whereKey($product->sound_kit_revision_id)->value('public_id'),
            'license_version_id' => $product->license_version_id, 'price_minor' => $product->price_minor,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data, SoundKitProduct $existing, SoundKitDraft $draft): array
    {
        $slug = $data['slug'];
        if (! is_string($slug) || strlen($slug) > 80 || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $slug) !== 1) {
            $this->reject('slug', 'Use lowercase letters, numbers and single hyphens, up to 80 characters.');
        }
        if (SoundKitProduct::query()->where('slug', $slug)->when($existing->exists, fn ($query) => $query->whereKeyNot($existing->getKey()))->lockForUpdate()->exists()) {
            $this->reject('slug', 'Another kit already uses this web address.');
        }
        $revision = $data['revision_public_id'] ?? null;
        if ($revision !== null && (! is_string($revision) || preg_match('/\A[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}\z/D', $revision) !== 1)) {
            $this->reject('revision_public_id', 'Choose an archive revision from the list.');
        }
        $licenseId = $data['license_version_id'] ?? null;
        $licenseId = is_string($licenseId) && ctype_digit($licenseId) ? (int) $licenseId : $licenseId;
        if ($licenseId !== null) {
            if (! is_int($licenseId) || $licenseId < 1) {
                $this->reject('license_version_id', 'Choose a license version from the list.');
            }
            SoundKitLicenseAttachment::assertKitAttachable(LicenseVersion::query()->with('template')->find($licenseId));
        }
        $price = $data['price_minor'] ?? null;
        $price = is_string($price) && ctype_digit($price) ? (int) $price : $price;
        if ($price !== null && (! is_int($price) || $price < 1 || $price > 2147483647)) {
            $this->reject('price_minor', 'Enter a whole number of cents from 1 to 2147483647, or leave it empty.');
        }

        return ['sound_kit_draft_id' => $draft->id, 'title' => $this->line($data['title'], 'title', 160), 'slug' => $slug, 'summary' => $this->block($data['summary'] ?? null),
            'revision_public_id' => $revision, 'license_version_id' => $licenseId, 'price_minor' => $price];
    }

    private function line(mixed $value, string $field, int $max): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject($field, 'Enter plain text.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > $max || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject($field, 'Enter 1 to '.$max.' characters of plain text on one line.');
        }

        return $value;
    }

    private function block(mixed $value): ?string
    {
        if ($value === null || (is_string($value) && trim($value) === '')) {
            return null;
        }
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject('summary', 'Enter plain text.');
        }
        $value = trim(str_replace("\r\n", "\n", $value));
        if (mb_strlen($value) > 2000 || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject('summary', 'Enter up to 2000 characters of plain text.');
        }

        return $value;
    }

    private function note(mixed $value): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) {
            $this->reject('rights_note', 'Enter plain text.');
        }
        $value = trim($value);
        if ($value === '' || mb_strlen($value) > 500 || preg_match('/[\x00-\x1F\x7F<>]/u', $value) === 1) {
            $this->reject('rights_note', 'Describe how rights were cleared in 1 to 500 characters of plain text on one line.');
        }

        return $value;
    }

    private function lockActor(User $actor): User
    {
        $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }

        return $current;
    }

    private function reject(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
