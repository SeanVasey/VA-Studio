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

/** A bounded explicit review of source replacement, never a license review or publication. */
final class BulkReplaceLicenseDraftSource
{
    public const MAX_DRAFTS = 25;

    public const MAX_REVIEW_BYTES = 1048576;

    public const REOPEN_MESSAGE = 'The selected license drafts changed or this review is no longer available. Review the current selected drafts again.';

    private const KEYS = ['schema_version', 'intent', 'actor_id', 'authored_source', 'drafts'];

    private const ROW_KEYS = ['template_id', 'version_id', 'version', 'template_hash', 'version_hash', 'template_audit_id', 'version_audit_id', 'template', 'before', 'after'];

    private const CONTENT_KEYS = ['authored_source', 'structured_terms', 'effective_from', 'effective_until'];

    private const AUDIT_ACTION = 'rights.license.draft_source_bulk_updated';

    /** Models are identity/parent hints only. This explicit preview is the capture point. */
    public function review(array $versions, string $source, User $actor): array
    {
        return $this->transaction($actor, function (User $current) use ($versions, $source): array {
            if (! array_is_list($versions) || count($versions) < 1 || count($versions) > self::MAX_DRAFTS) {
                $this->reject('Select between one and 25 editable license drafts on the current page.');
            }
            $hints = [];
            foreach ($versions as $version) {
                if (! $version instanceof LicenseVersion || ! $version->exists) {
                    $this->reject();
                }
                $id = $this->positive($version->getKey());
                if (isset($hints[$id])) {
                    $this->reject();
                }
                $hints[$id] = $this->positive($version->license_template_id);
            }
            ksort($hints, SORT_NUMERIC);
            [$templates, $locked] = $this->lock($hints);
            $review = $this->project($templates, $locked, $source, $current);
            $this->bounded($review);
            $this->assertUnchanged($review);
            $this->assertFinalState($review, [], [], $this->actor($current));

            return $review;
        });
    }

    public function applyReviewed(array $review, User $actor): array
    {
        return $this->transaction($actor, function (User $current) use ($review): array {
            $this->validateReview($review, $current);
            $hints = array_column($review['drafts'], 'template_id', 'version_id');
            [$templates, $versions] = $this->lock($hints);
            // Compare the current locked projection; never replace the supplied review with it.
            if (! $this->same($review, $this->project($templates, $versions, $review['authored_source'], $current))) {
                $this->reject();
            }
            $batchHash = CanonicalJson::hash($review);
            $expectedRows = [];
            $ownAudits = [];
            $changed = [];
            $unchanged = [];
            foreach ($review['drafts'] as $row) {
                $version = $versions[$row['version_id']];
                $before = $version->getAttributes();
                if ($row['before']['authored_source'] === $row['after']['authored_source']) {
                    $expectedRows[$version->id] = $before;
                    $unchanged[] = (int) $version->id;

                    continue;
                }
                $authors = $this->authors($version, $current);
                $version->authored_source = $review['authored_source'];
                $version->author_id = $current->id;
                $version->content_author_ids = $authors;
                $version->save();
                $saved = LicenseVersion::query()->lockForUpdate()->find($version->id);
                if ($saved === null || $saved->authored_source !== $review['authored_source']
                    || $saved->author_id !== $current->id || $saved->content_author_ids !== $authors
                    || ! $this->same($row['after'], $this->content($saved))) {
                    $this->reject();
                }
                $retainedBefore = $before;
                $retainedAfter = $saved->getAttributes();
                foreach (['authored_source', 'author_id', 'content_author_ids', 'updated_at'] as $field) {
                    unset($retainedBefore[$field], $retainedAfter[$field]);
                }
                if (! $this->same($retainedBefore, $retainedAfter)) {
                    $this->reject();
                }
                $expectedRows[$saved->id] = $saved->getAttributes();
                $context = ['schema_version' => 1, 'changed_fields' => ['authored_source'],
                    'canonicalization_version' => CanonicalJson::VERSION, 'batch_review_hash' => $batchHash,
                    'before_hash' => CanonicalJson::hash($row['before']), 'after_hash' => CanonicalJson::hash($row['after'])];
                $audit = AuditEvent::create(['actor_id' => $current->id, 'action' => self::AUDIT_ACTION,
                    'subject_type' => LicenseVersion::class, 'subject_id' => $saved->id, 'context' => $context]);
                $ownAudits[$saved->id] = ['id' => $this->positive($audit->getKey()), 'context' => $context];
                $changed[] = (int) $saved->id;
            }
            // Later save/audit observers can change an earlier or unchanged row in this transaction.
            // Prove the entire result, and our actual event identities, after all writes have finished.
            foreach ($review['drafts'] as $row) {
                $template = LicenseTemplate::query()->lockForUpdate()->find($row['template_id']);
                $version = LicenseVersion::query()->lockForUpdate()->find($row['version_id']);
                if ($template === null || $version === null
                    || ! hash_equals($row['template_hash'], CanonicalJson::hash($template->getAttributes()))
                    || $row['template_audit_id'] !== $this->auditId(LicenseTemplate::class, $template->id)
                    || ! $this->same($expectedRows[$row['version_id']], $version->getAttributes())) {
                    $this->reject();
                }
                $own = $ownAudits[$version->id] ?? null;
                if ($this->auditId(LicenseVersion::class, $version->id) !== ($own['id'] ?? $row['version_audit_id'])) {
                    $this->reject();
                }
                if ($own !== null) {
                    $actual = AuditEvent::find($own['id']);
                    if ($actual === null || $actual->actor_id !== $current->id || $actual->action !== self::AUDIT_ACTION
                        || $actual->subject_type !== LicenseVersion::class || $actual->subject_id !== $version->id
                        || ! $this->same($own['context'], $actual->context)) {
                        $this->reject();
                    }
                }
            }
            $this->assertFinalState($review, $expectedRows, $ownAudits, $this->actor($current));

            return ['changed_ids' => $changed, 'unchanged_ids' => $unchanged];
        });
    }

    private function transaction(User $actor, Closure $operation): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Bulk license draft source editing requires a standalone transaction.');
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

    /** All parent fences precede every version fence and the first ordinary audit/baseline read. */
    private function lock(array $hints): array
    {
        $templateIds = array_values(array_unique(array_values($hints)));
        sort($templateIds, SORT_NUMERIC);
        $templates = [];
        foreach ($templateIds as $id) {
            $template = LicenseTemplate::query()->lockForUpdate()->find($id);
            if ($template === null) {
                $this->reject();
            }
            $templates[$id] = $template;
        }
        $versions = [];
        foreach ($hints as $id => $parent) {
            $version = LicenseVersion::query()->lockForUpdate()->find($id);
            if ($version === null || $version->license_template_id !== $parent
                || $version->status !== 'draft' || $version->published_at !== null) {
                $this->reject();
            }
            $versions[$id] = $version;
        }

        return [$templates, $versions];
    }

    private function project(array $templates, array $versions, string $source, User $actor): array
    {
        $rows = [];
        foreach ($versions as $version) {
            $template = $templates[$version->license_template_id];
            $before = $this->content($version);
            try {
                // Validation may normalize values; retained terms and dates are never rewritten.
                app(LicenseContent::class)->validate(array_replace($before, ['authored_source' => $source]));
            } catch (ValidationException $exception) {
                if (isset($exception->errors()['authored_source'])) {
                    throw ValidationException::withMessages(['authored_source' => $exception->errors()['authored_source']]);
                }
                $this->reject('A selected draft has invalid retained terms or availability dates. Review it in its editor first.');
            }
            $rows[] = ['template_id' => $this->positive($template->id), 'version_id' => $this->positive($version->id),
                'version' => $this->positive($version->version), 'template_hash' => CanonicalJson::hash($template->getAttributes()),
                'version_hash' => CanonicalJson::hash($version->getAttributes()),
                'template_audit_id' => $this->auditId(LicenseTemplate::class, $template->id),
                'version_audit_id' => $this->auditId(LicenseVersion::class, $version->id),
                'template' => $template->only(SaveLicenseTemplate::FIELDS), 'before' => $before,
                'after' => array_replace($before, ['authored_source' => $source])];
        }

        return ['schema_version' => 1, 'intent' => 'replace_license_draft_source', 'actor_id' => (int) $actor->id,
            'authored_source' => $source, 'drafts' => $rows];
    }

    private function content(LicenseVersion $version): array
    {
        return ['authored_source' => $version->authored_source, 'structured_terms' => $version->structured_terms,
            'effective_from' => $version->effective_from?->utc()->format('Y-m-d H:i:s'),
            'effective_until' => $version->effective_until?->utc()->format('Y-m-d H:i:s')];
    }

    private function assertUnchanged(array $review): void
    {
        foreach ($review['drafts'] as $row) {
            $template = LicenseTemplate::query()->lockForUpdate()->find($row['template_id']);
            $version = LicenseVersion::query()->lockForUpdate()->find($row['version_id']);
            if ($template === null || $version === null
                || ! hash_equals($row['template_hash'], CanonicalJson::hash($template->getAttributes()))
                || ! hash_equals($row['version_hash'], CanonicalJson::hash($version->getAttributes()))
                || $row['template_audit_id'] !== $this->auditId(LicenseTemplate::class, $template->id)
                || $row['version_audit_id'] !== $this->auditId(LicenseVersion::class, $version->id)) {
                $this->reject();
            }
        }
    }

    private function validateReview(array $review, User $actor): void
    {
        if (! $this->keys($review, self::KEYS) || $review['schema_version'] !== 1
            || $review['intent'] !== 'replace_license_draft_source' || ! is_string($review['authored_source'])
            || ! is_array($review['drafts']) || ! array_is_list($review['drafts'])
            || count($review['drafts']) < 1 || count($review['drafts']) > self::MAX_DRAFTS) {
            $this->reject();
        }
        $this->positive($review['actor_id']);
        if ($review['actor_id'] !== (int) $actor->id) {
            throw new AuthorizationException('This bulk source review belongs to a different operator.');
        }
        $previous = 0;
        $templates = [];
        foreach ($review['drafts'] as $row) {
            if (! is_array($row) || ! $this->keys($row, self::ROW_KEYS)) {
                $this->reject();
            }
            foreach (['template_id', 'version_id', 'version'] as $field) {
                $this->positive($row[$field]);
            }
            if ($row['version_id'] <= $previous) {
                $this->reject();
            }
            $previous = $row['version_id'];
            foreach (['template_audit_id', 'version_audit_id'] as $field) {
                if (! is_int($row[$field]) || $row[$field] < 0) {
                    $this->reject();
                }
            }
            foreach (['template_hash', 'version_hash'] as $field) {
                if (! is_string($row[$field]) || ! preg_match('/\A[a-f0-9]{64}\z/D', $row[$field])) {
                    $this->reject();
                }
            }
            if (! is_array($row['template']) || ! $this->keys($row['template'], SaveLicenseTemplate::FIELDS)
                || array_filter($row['template'], fn ($value): bool => ! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) !== []
                || ! is_array($row['before']) || ! is_array($row['after'])
                || ! $this->keys($row['before'], self::CONTENT_KEYS) || ! $this->keys($row['after'], self::CONTENT_KEYS)
                || ! is_string($row['before']['authored_source']) || $row['after']['authored_source'] !== $review['authored_source']
                || ! $this->same(array_diff_key($row['before'], ['authored_source' => true]), array_diff_key($row['after'], ['authored_source' => true]))) {
                $this->reject();
            }
            $parent = [$row['template_hash'], $row['template_audit_id'], $row['template']];
            if (isset($templates[$row['template_id']]) && ! $this->same($templates[$row['template_id']], $parent)) {
                $this->reject();
            }
            $templates[$row['template_id']] = $parent;
        }
        $this->bounded($review);
    }

    /** No Eloquent retrieval or authority callback may run after this whole-batch proof. */
    private function assertFinalState(array $review, array $expectedRows, array $ownAudits, User $actor): void
    {
        $rawActor = DB::table('users')->where('id', $actor->id)->lockForUpdate()->first();
        if ($rawActor === null || ! $this->same($actor->getAttributes(), (array) $rawActor)) {
            throw new AuthorizationException('Current operator authority changed during the bulk source review.');
        }
        $templateIds = array_values(array_unique(array_column($review['drafts'], 'template_id')));
        $versionIds = array_column($review['drafts'], 'version_id');
        $templates = DB::table('license_templates')->whereIn('id', $templateIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $versions = DB::table('license_versions')->whereIn('id', $versionIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        if ($templates->count() !== count($templateIds) || $versions->count() !== count($versionIds)) {
            $this->reject();
        }
        foreach ($review['drafts'] as $row) {
            $template = $templates[$row['template_id']];
            $version = $versions[$row['version_id']];
            $expectedHash = isset($expectedRows[$row['version_id']]) ? CanonicalJson::hash($expectedRows[$row['version_id']]) : $row['version_hash'];
            if (! hash_equals($row['template_hash'], CanonicalJson::hash((array) $template))
                || ! hash_equals($expectedHash, CanonicalJson::hash((array) $version))
                || $row['template_audit_id'] !== $this->auditId(LicenseTemplate::class, $row['template_id'])) {
                $this->reject();
            }
            $own = $ownAudits[$row['version_id']] ?? null;
            if ($this->auditId(LicenseVersion::class, $row['version_id']) !== ($own['id'] ?? $row['version_audit_id'])) {
                $this->reject();
            }
            if ($own === null) {
                continue;
            }
            $actual = DB::table('audit_events')->where('id', $own['id'])->lockForUpdate()->first();
            if ($actual === null || $actual->actor_id !== $actor->id || $actual->action !== self::AUDIT_ACTION
                || $actual->subject_type !== LicenseVersion::class || $actual->subject_id !== $row['version_id']) {
                $this->reject();
            }
            try {
                $context = json_decode($actual->context, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $this->reject();
            }
            if (! is_array($context) || ! $this->same($own['context'], $context)) {
                $this->reject();
            }
        }
    }

    private function authors(LicenseVersion $version, User $actor): array
    {
        $authors = $version->content_author_ids ?? [];
        if (! is_array($authors) || ! array_is_list($authors)
            || array_filter($authors, fn ($id): bool => ! is_int($id) || $id < 1) !== []) {
            $this->reject();
        }

        return array_values(array_unique([...$authors, $this->positive($version->author_id), $actor->id]));
    }

    private function auditId(string $type, int $id): int
    {
        return (int) (DB::table('audit_events')->where('subject_type', $type)->where('subject_id', $id)->orderByDesc('id')->value('id') ?? 0);
    }

    private function positive(mixed $id): int
    {
        if (! is_int($id) || $id < 1) {
            $this->reject();
        }

        return $id;
    }

    private function keys(array $value, array $keys): bool
    {
        return array_diff(array_keys($value), $keys) === [] && array_diff($keys, array_keys($value)) === [];
    }

    private function bounded(array $review): void
    {
        try {
            $bytes = strlen(CanonicalJson::encode($review));
        } catch (InvalidArgumentException|JsonException) {
            $this->reject();
        }
        if ($bytes > self::MAX_REVIEW_BYTES) {
            $this->reject('The selected source review is too large. Select fewer drafts and review them again.');
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

    private function reject(string $message = self::REOPEN_MESSAGE): never
    {
        throw ValidationException::withMessages(['licenses' => $message]);
    }
}
