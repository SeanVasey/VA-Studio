<?php

namespace App\Domain\Rights;

use App\Domain\Rights\Models\LicenseTemplate;
use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use LogicException;

/** Existing template identity only; license content and lifecycle have separate commands. */
final class SaveLicenseTemplate
{
    public const FIELDS = ['name', 'slug', 'type'];

    public const FROZEN_MESSAGE = 'Template identity is frozen after a version enters review. Create a new successor template for identity changes; retained versions and offers stay unchanged.';

    public function create(array $data, User $actor): LicenseTemplate
    {
        return $this->transaction($actor, function (User $current) use ($data): LicenseTemplate {
            $template = new LicenseTemplate;
            $after = $this->validate($data, $template);
            $template->fill($after)->save();
            $this->audit($template, $current, null, $after, self::FIELDS);

            return $template;
        });
    }

    /** The exact locked baseline supplies the form and is never refreshed at submission. */
    public function review(LicenseTemplate $template, User $actor): array
    {
        return $this->transaction($actor, fn (User $current): array => $this->capture($this->lockTemplate($template->getKey()), $current));
    }

    public function updateReviewed(array $review, array $data, User $actor): LicenseTemplate
    {
        return $this->transaction($actor, function (User $current) use ($review, $data): LicenseTemplate {
            $this->validateReview($review, $current);
            $template = $this->lockTemplate($review['template_id']);
            if (CanonicalJson::encode($this->capture($template, $current)) !== CanonicalJson::encode($review)) {
                $this->reject('This template changed since you opened it. Close and reopen the editor, then review your changes.');
            }
            $before = $template->only(self::FIELDS);
            $after = $this->validate($data, $template);
            $changed = array_values(array_filter(self::FIELDS, fn (string $field): bool => $before[$field] !== $after[$field]));
            if ($changed === []) {
                return $template;
            }
            $template->fill($after)->save();
            $this->audit($template, $current, $before, $after, $changed);

            return $template;
        });
    }

    private function transaction(User $actor, Closure $command): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('License template authoring requires a standalone transaction.');
        }
        try {
            return DB::transaction(function () use ($actor, $command): mixed {
                $current = $actor->exists ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
                if ($current === null) {
                    throw new AuthorizationException;
                }
                Gate::forUser($current)->authorize('administer-catalog', [true]);
                if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
                    throw new AuthorizationException('Admin multi-factor authentication is required.');
                }

                return $command($current);
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (str_contains($exception->getMessage(), 'license_templates_slug_unique') || str_contains($exception->getMessage(), 'license_templates.slug')) {
                throw ValidationException::withMessages(['slug' => 'This template URL is already in use.']);
            }
            throw $exception;
        }
    }

    private function lockTemplate(mixed $id): LicenseTemplate
    {
        if (! is_int($id) || $id < 1) {
            $this->reject('Choose a current template.');
        }
        // Match review submission and version creation: actor -> template -> versions.
        $template = LicenseTemplate::query()->lockForUpdate()->findOrFail($id);
        $versions = $template->versions()->orderBy('id')->lockForUpdate()->get(['id', 'status', 'published_at']);
        if ($versions->contains(fn ($version): bool => $version->status !== 'draft' || $version->published_at !== null)) {
            $this->reject(self::FROZEN_MESSAGE);
        }

        return $template;
    }

    private function capture(LicenseTemplate $template, User $actor): array
    {
        // First consistent read in our standalone transaction, after the template fence.
        // Prior participating edits/audits have committed; this avoids locking unrelated audit rows.
        // The audit identity detects A -> B -> A even within timestamp precision, without a new schema revision.
        $audit = AuditEvent::query()->where('subject_type', LicenseTemplate::class)->where('subject_id', $template->id)
            ->orderByDesc('id')->first(['id']);

        return ['schema_version' => 1, 'intent' => 'edit', 'actor_id' => (int) $actor->id,
            'template_id' => (int) $template->id, 'row_hash' => CanonicalJson::hash($template->getAttributes()),
            'audit_id' => (int) ($audit?->id ?? 0), 'display' => $template->only(self::FIELDS)];
    }

    private function validateReview(array $review, User $actor): void
    {
        $keys = ['schema_version', 'intent', 'actor_id', 'template_id', 'row_hash', 'audit_id', 'display'];
        if (array_diff(array_keys($review), $keys) !== [] || array_diff($keys, array_keys($review)) !== []
            || $review['schema_version'] !== 1 || $review['intent'] !== 'edit'
            || ! is_int($review['actor_id']) || $review['actor_id'] < 1
            || ! is_int($review['template_id']) || $review['template_id'] < 1
            || ! is_int($review['audit_id']) || $review['audit_id'] < 0
            || ! is_string($review['row_hash']) || ! preg_match('/\A[a-f0-9]{64}\z/D', $review['row_hash'])
            || ! is_array($review['display']) || array_diff(array_keys($review['display']), self::FIELDS) !== []
            || array_diff(self::FIELDS, array_keys($review['display'])) !== []
            || array_filter($review['display'], fn ($value): bool => ! is_string($value) || ! mb_check_encoding($value, 'UTF-8')) !== []) {
            $this->reject('Close and reopen the current template before saving.');
        }
        if ($review['actor_id'] !== (int) $actor->id) {
            throw new AuthorizationException('This template edit belongs to a different operator.');
        }
    }

    private function validate(array $data, LicenseTemplate $template): array
    {
        if (array_diff(array_keys($data), self::FIELDS) !== []) {
            $this->reject('Only template name, URL and type may be saved here. License terms and publication use their separate actions.');
        }
        foreach (self::FIELDS as $field) {
            if (is_string($data[$field] ?? null)) {
                $data[$field] = trim($data[$field]);
            }
        }

        return Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', Rule::unique('license_templates', 'slug')->ignore($template)],
            'type' => ['required', 'string', Rule::in(['non-exclusive', 'exclusive', 'free'])],
        ])->validate();
    }

    private function audit(LicenseTemplate $template, User $actor, ?array $before, array $after, array $changed): void
    {
        AuditEvent::record($before === null ? 'rights.license_template.created' : 'rights.license_template.updated', $template, [
            'schema_version' => 1, 'changed_fields' => $changed, 'canonicalization_version' => CanonicalJson::VERSION,
            'before_hash' => $before === null ? null : CanonicalJson::hash($before), 'after_hash' => CanonicalJson::hash($after),
        ], $actor->id);
    }

    private function reject(string $message): never
    {
        throw ValidationException::withMessages(['name' => $message]);
    }
}
