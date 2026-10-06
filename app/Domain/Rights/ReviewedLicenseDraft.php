<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseTemplate;
use App\Domain\Rights\Models\LicenseVersion;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use LogicException;

/** Captured operator edits only. The legacy draft command retains its caller-owned transaction contract. */
final class ReviewedLicenseDraft
{
    public const FIELDS = ['authored_source', 'structured_terms', 'effective_from', 'effective_until'];

    public const REOPEN_MESSAGE = 'This license draft changed or its edit review is no longer available. Close and reopen the editor, then review your changes.';

    public function review(LicenseVersion $version, User $actor): array
    {
        return $this->transaction($actor, function (User $current) use ($version): array {
            if (! $version->exists) {
                $this->reject();
            }
            // The retained parent is only a lock-order hint, checked against the locked version.
            [$template, $locked] = $this->lockDraft($version->license_template_id, $version->getKey());
            $review = $this->capture($template, $locked, $current);
            $this->actor($current);

            return $review;
        });
    }

    public function updateReviewed(array $review, array $data, User $actor): LicenseVersion
    {
        return $this->transaction($actor, function (User $current) use ($review, $data): LicenseVersion {
            $this->validateReview($review, $current);
            [$template, $version] = $this->lockDraft($review['template_id'], $review['version_id']);
            if (! $this->same($review, $this->capture($template, $version, $current))) {
                $this->reject();
            }
            if (array_diff(array_keys($data), self::FIELDS) !== []) {
                throw ValidationException::withMessages(['license' => 'Only draft source, structured terms and availability dates may be edited here.']);
            }
            $content = app(LicenseContent::class)->validate($data);
            $before = $this->display($version);
            $original = $version->getAttributes();
            $version->fill($content);
            $after = $this->display($version);
            // Native JSON object-key order is storage-defined; list order and every scalar remain significant.
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => CanonicalJson::encode($before[$field]) !== CanonicalJson::encode($after[$field])));
            if ($changed === []) {
                $version->setRawAttributes($original, true);
                $this->actor($current);

                return $version;
            }
            // Keep the existing authorship rule: every content editor remains ineligible to approve.
            $version->content_author_ids = array_values(array_unique([...($version->content_author_ids ?? [$version->author_id]), $current->id]));
            $version->author_id = $current->id;
            $version->save();
            $saved = $version->attributesToArray();
            $audit = AuditEvent::create(['actor_id' => $current->id, 'action' => 'rights.license.draft_updated',
                'subject_type' => LicenseVersion::class, 'subject_id' => $version->id, 'context' => [
                    'schema_version' => 1, 'changed_fields' => $changed, 'canonicalization_version' => CanonicalJson::VERSION,
                    'before_hash' => CanonicalJson::hash($before), 'after_hash' => CanonicalJson::hash($this->display($version)),
                ],
            ]);
            // Observer/audit work may withdraw authority in this same transaction. Recheck before commit.
            $this->actor($current);
            [$finalTemplate, $finalVersion] = $this->lockDraft($review['template_id'], $review['version_id']);
            if (! $this->same($saved, $finalVersion->attributesToArray())
                || ! hash_equals($review['template_hash'], CanonicalJson::hash($finalTemplate->getAttributes()))
                || $review['template_audit_id'] !== $this->auditId(LicenseTemplate::class, $finalTemplate->id)
                || (int) $audit->id !== $this->auditId(LicenseVersion::class, $finalVersion->id)) {
                $this->reject();
            }
            $this->actor($current);

            return $finalVersion;
        });
    }

    private function transaction(User $actor, Closure $operation): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reviewed license draft editing requires a standalone transaction.');
        }

        return DB::transaction(fn () => $operation($this->actor($actor)));
    }

    private function actor(User $actor): User
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

    private function lockDraft(mixed $templateId, mixed $versionId): array
    {
        if (! is_int($templateId) || $templateId < 1 || ! is_int($versionId) || $versionId < 1) {
            $this->reject();
        }
        // Same order as template edits, version creation and review submission.
        $template = LicenseTemplate::query()->lockForUpdate()->find($templateId);
        $version = LicenseVersion::query()->lockForUpdate()->find($versionId);
        if ($template === null || $version === null || $version->license_template_id !== $template->id
            || $version->status !== 'draft' || $version->published_at !== null) {
            $this->reject();
        }

        return [$template, $version];
    }

    private function capture(LicenseTemplate $template, LicenseVersion $version, User $actor): array
    {
        // These are the first consistent reads in our own transaction, after both resource fences.
        // Participating writers audit under those locks; audit identities catch same-second A -> B -> A.
        return ['schema_version' => 1, 'intent' => 'edit_license_draft', 'actor_id' => (int) $actor->id,
            'template_id' => (int) $template->id, 'version_id' => (int) $version->id,
            'template_hash' => CanonicalJson::hash($template->getAttributes()), 'version_hash' => CanonicalJson::hash($version->getAttributes()),
            'template_audit_id' => $this->auditId(LicenseTemplate::class, $template->id),
            'version_audit_id' => $this->auditId(LicenseVersion::class, $version->id),
            'template' => $template->only(SaveLicenseTemplate::FIELDS), 'display' => $this->display($version)];
    }

    private function display(LicenseVersion $version): array
    {
        return ['license_template_id' => (int) $version->license_template_id, 'authored_source' => $version->authored_source,
            'structured_terms' => $version->structured_terms,
            'effective_from' => $version->effective_from?->utc()->format('Y-m-d H:i:s'),
            'effective_until' => $version->effective_until?->utc()->format('Y-m-d H:i:s')];
    }

    private function auditId(string $type, int $id): int
    {
        return (int) (AuditEvent::query()->where('subject_type', $type)->where('subject_id', $id)->orderByDesc('id')->value('id') ?? 0);
    }

    private function validateReview(array $review, User $actor): void
    {
        $keys = ['schema_version', 'intent', 'actor_id', 'template_id', 'version_id', 'template_hash', 'version_hash', 'template_audit_id', 'version_audit_id', 'template', 'display'];
        if (array_diff(array_keys($review), $keys) !== [] || array_diff($keys, array_keys($review)) !== []
            || $review['schema_version'] !== 1 || $review['intent'] !== 'edit_license_draft') {
            $this->reject();
        }
        foreach (['actor_id', 'template_id', 'version_id', 'template_audit_id', 'version_audit_id'] as $field) {
            if (! is_int($review[$field]) || $review[$field] < (str_ends_with($field, 'audit_id') ? 0 : 1)) {
                $this->reject();
            }
        }
        foreach (['template_hash', 'version_hash'] as $field) {
            if (! is_string($review[$field]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $review[$field])) {
                $this->reject();
            }
        }
        if (! is_array($review['template']) || ! is_array($review['display'])) {
            $this->reject();
        }
        if ($review['actor_id'] !== (int) $actor->id) {
            throw new AuthorizationException('This license edit belongs to a different operator.');
        }
    }

    private function same(array $left, array $right): bool
    {
        try {
            return CanonicalJson::encode($left) === CanonicalJson::encode($right);
        } catch (InvalidArgumentException|JsonException) {
            return false;
        }
    }

    private function reject(): never
    {
        throw ValidationException::withMessages(['license' => self::REOPEN_MESSAGE]);
    }
}
