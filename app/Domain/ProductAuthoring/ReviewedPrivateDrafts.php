<?php

namespace App\Domain\ProductAuthoring;

use App\Models\User;
use App\Support\Access\AdminMultiFactor;
use App\Support\Audit\AuditEvent;
use App\Support\CanonicalJson;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;
use PDO;
use Throwable;

/** Shared private authoring fences only. Concrete families retain distinct tables and formats. */
abstract class ReviewedPrivateDrafts
{
    public const MAX_VERSIONS = 100;

    public const REOPEN = 'This private draft or review changed. Keep a copy of your text, close and reopen the editor, then review again.';

    abstract protected function draftClass(): string;

    abstract protected function versionClass(): string;

    abstract protected function format(): PrivateDraftManifest;

    public function review(?PrivateDraft $draft, array $authored, User $actor, ?string $openedStateHash = null): array
    {
        return $this->transaction($actor, function (User $current) use ($draft, $authored, $openedStateHash): array {
            $manifest = $this->format()->make($authored);
            $state = $draft === null ? null : $this->state($this->identity($draft));
            if ($state !== null && (CanonicalJson::hash($draft->getRawOriginal()) !== CanonicalJson::hash($state['draft'])
                || ($openedStateHash !== null && ! hash_equals($openedStateHash, $this->stateHash($state))))) {
                $this->reject();
            }
            if ($state === null && $openedStateHash !== null) {
                $this->reject();
            }
            $review = ['schema' => 'private-product-review-v1', 'kind' => $this->format()->kind(),
                'actor_id' => (int) $current->id, 'actor_hash' => $this->actorHash($current),
                'draft_id' => $state === null ? null : (int) $state['draft']['id'],
                'version' => $state === null ? 0 : (int) $state['draft']['version'],
                'before_state_hash' => $state === null ? null : $this->stateHash($state),
                'before_manifest' => $state === null ? null : end($state['manifests']),
                'manifest' => $manifest, 'manifest_hash' => CanonicalJson::hash($manifest), 'nonce' => bin2hex(random_bytes(16))];
            $review['signature'] = $this->sign($review);
            $this->finalUnchanged($current, $state);

            return $review;
        });
    }

    public function applyReviewed(array $review, User $actor): PrivateDraft
    {
        return $this->transaction($actor, function (User $current) use ($review): PrivateDraft {
            $this->validateReview($review, $current);
            $manifest = $this->format()->verified($review['manifest']);
            if ($review['draft_id'] === null) {
                // Actor serialization also fences same-operator create replay. The unique hash
                // prevents a second row even if another participating connection races it.
                $existing = $this->rawRows($this->draftTable(), ['creation_review_hash' => $review['signature']])[0] ?? null;
                if ($existing !== null) {
                    $state = $this->state((int) $existing['id']);
                    if ($state['draft']['version'] !== 1 || $state['draft']['created_by'] !== $current->id
                        || ! hash_equals(CanonicalJson::hash($state['manifests'][0]), $review['manifest_hash'])) {
                        $this->reject();
                    }
                    $this->finalUnchanged($current, $state);

                    return $this->hydrate($state['draft']);
                }
                $before = null;
            } else {
                $before = $this->state($review['draft_id']);
                if ($before['draft']['version'] !== $review['version']
                    || ! hash_equals($this->stateHash($before), $review['before_state_hash'])
                    || CanonicalJson::hash(end($before['manifests'])) !== CanonicalJson::hash($review['before_manifest'])) {
                    $this->reject();
                }
                if (hash_equals(CanonicalJson::hash(end($before['manifests'])), $review['manifest_hash'])) {
                    $this->finalUnchanged($current, $before);

                    return $this->hydrate($before['draft']);
                }
                if ($before['draft']['version'] >= self::MAX_VERSIONS) {
                    $this->reject('This draft has reached its 100-version authoring bound. Retain its history for an explicit follow-on migration.');
                }
            }
            $timestamp = now()->utc()->format('Y-m-d H:i:s');
            $class = $this->draftClass();
            $draft = $before === null ? new $class : $this->hydrate($before['draft']);
            $number = $before === null ? 1 : $before['draft']['version'] + 1;
            $expected = $before === null
                ? ['title' => $manifest['title'], 'version' => 1, 'creation_review_hash' => $review['signature'],
                    'created_by' => (int) $current->id, 'created_at' => $timestamp, 'updated_at' => $timestamp]
                : array_replace($before['draft'], ['title' => $manifest['title'], 'version' => $number, 'updated_at' => $timestamp]);
            if ($before === null) {
                $draft->fill($expected)->save();
            }
            $versionClass = $this->versionClass();
            $version = $versionClass::create(['draft_id' => (int) $draft->id, 'number' => $number, 'manifest' => $manifest,
                'manifest_sha256' => $review['manifest_hash'], 'created_by' => (int) $current->id, 'created_at' => $timestamp]);
            if ($before !== null) {
                $draft->fill(['title' => $manifest['title'], 'version' => $number, 'updated_at' => $timestamp])->save();
            }
            $context = $this->auditContext($number, (int) $version->id, $review['manifest_hash'],
                $before === null ? null : CanonicalJson::hash(end($before['manifests'])), $review['signature']);
            $audit = AuditEvent::create(['actor_id' => (int) $current->id, 'action' => $this->action($number),
                'subject_type' => $class, 'subject_id' => (int) $draft->id, 'context' => $context]);

            // After every model/Gate/MFA/audit callback, only observer-free current locking
            // reads run. Do not append a fresh()/Gate call after this whole-aggregate proof.
            $this->assertActorUnchanged($current);
            $final = $this->state((int) $draft->id);
            $expected['id'] = (int) $draft->id;
            if (CanonicalJson::hash($expected) !== CanonicalJson::hash($final['draft'])
                || CanonicalJson::hash(end($final['manifests'])) !== $review['manifest_hash']
                || (int) end($final['versions'])['id'] !== (int) $version->id
                || (int) end($final['audits'])['id'] !== (int) $audit->id
                || CanonicalJson::hash(array_slice($final['versions'], 0, -1)) !== CanonicalJson::hash($before['versions'] ?? [])
                || CanonicalJson::hash(array_slice($final['audits'], 0, -1)) !== CanonicalJson::hash($before['audits'] ?? [])) {
                $this->reject();
            }
            $this->assertActorUnchanged($current);

            return $this->hydrate($final['draft']);
        });
    }

    public function snapshot(int $id, User $actor): array
    {
        return $this->transaction($actor, function (User $current) use ($id): array {
            $state = $this->state($id);
            $history = [];
            foreach ($state['versions'] as $index => $version) {
                $history[] = ['id' => (int) $version['id'], 'number' => (int) $version['number'],
                    'manifest' => $state['manifests'][$index], 'manifest_hash' => $version['manifest_sha256'],
                    'created_at' => $version['created_at']];
            }
            $result = ['id' => (int) $state['draft']['id'], 'version' => (int) $state['draft']['version'],
                'manifest' => end($state['manifests']), 'state_hash' => $this->stateHash($state), 'history' => array_reverse($history)];
            $this->finalUnchanged($current, $state);

            return $result;
        });
    }

    private function state(int $id): array
    {
        $draft = $id > 0 ? ($this->rawRows($this->draftTable(), ['id' => $id])[0] ?? null) : null;
        if ($draft === null) {
            $this->reject();
        }
        if (! is_int($draft['version']) || $draft['version'] < 1 || $draft['version'] > self::MAX_VERSIONS
            || ! $this->hash($draft['creation_review_hash']) || ! is_int($draft['created_by']) || $draft['created_by'] < 1) {
            $this->reject();
        }
        // Every semantic/history/audit read is locking. Earlier callbacks may have established
        // a repeatable-read snapshot before the parent fence; nonlocking reads would be stale.
        $versions = $this->rawRows($this->versionTable(), ['draft_id' => $id], 'number');
        $audits = $this->rawRows('audit_events', ['subject_type' => $this->draftClass(), 'subject_id' => $id]);
        if (count($versions) !== $draft['version'] || count($audits) !== count($versions)) {
            $this->reject();
        }
        $manifests = [];
        $previousHash = null;
        foreach ($versions as $index => $version) {
            if ($version['number'] !== $index + 1 || $version['draft_id'] !== $id || ! $this->hash($version['manifest_sha256'])
                || ! is_int($version['created_by']) || $version['created_by'] < 1 || ! is_string($version['manifest']) || strlen($version['manifest']) > 150000) {
                $this->reject();
            }
            try {
                $manifest = json_decode(Crypt::decryptString($version['manifest']), true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $this->reject('This retained private draft cannot be verified with the current key. Preserve its original bytes and key for investigation.');
            }
            if (! is_array($manifest)) {
                $this->reject();
            }
            $manifest = $this->format()->verified($manifest);
            if (! hash_equals(CanonicalJson::hash($manifest), $version['manifest_sha256'])) {
                $this->reject();
            }
            $audit = $audits[$index];
            try {
                $context = json_decode($audit['context'], true, 64, JSON_THROW_ON_ERROR);
            } catch (Throwable) {
                $this->reject();
            }
            if (! is_array($context) || ! $this->hash($context['review_hash'] ?? null)
                || $audit['action'] !== $this->action($version['number']) || $audit['actor_id'] !== $version['created_by']
                || CanonicalJson::hash($context) !== CanonicalJson::hash($this->auditContext($version['number'], (int) $version['id'], $version['manifest_sha256'], $previousHash, $context['review_hash']))
                || ($index === 0 && ($context['review_hash'] !== $draft['creation_review_hash'] || $version['created_by'] !== $draft['created_by']))) {
                $this->reject();
            }
            $manifests[] = $manifest;
            $previousHash = $version['manifest_sha256'];
        }
        if (end($manifests)['title'] !== $draft['title']) {
            $this->reject();
        }

        return compact('draft', 'versions', 'audits', 'manifests');
    }

    private function validateReview(array $review, User $actor): void
    {
        $keys = ['schema', 'kind', 'actor_id', 'actor_hash', 'draft_id', 'version', 'before_state_hash', 'before_manifest', 'manifest', 'manifest_hash', 'nonce', 'signature'];
        if (count($review) !== count($keys) || array_diff(array_keys($review), $keys) !== []
            || $review['schema'] !== 'private-product-review-v1' || $review['kind'] !== $this->format()->kind()
            || $review['actor_id'] !== (int) $actor->id || ! $this->hash($review['actor_hash'])
            || ! hash_equals($this->actorHash($actor), $review['actor_hash']) || ! $this->hash($review['manifest_hash'])
            || ! is_array($review['manifest']) || ! $this->hash($review['signature'])
            || ! is_string($review['nonce']) || ! preg_match('/\A[a-f0-9]{32}\z/D', $review['nonce'])
            || ! hash_equals(CanonicalJson::hash($review['manifest']), $review['manifest_hash'])) {
            $this->reject();
        }
        if ($review['draft_id'] === null) {
            if ($review['version'] !== 0 || $review['before_state_hash'] !== null || $review['before_manifest'] !== null) {
                $this->reject();
            }
        } elseif (! is_int($review['draft_id']) || $review['draft_id'] < 1 || ! is_int($review['version']) || $review['version'] < 1
            || ! $this->hash($review['before_state_hash']) || ! is_array($review['before_manifest'])) {
            $this->reject();
        }
        $signature = $review['signature'];
        unset($review['signature']);
        if (! hash_equals($this->sign($review), $signature)) {
            $this->reject();
        }
    }

    private function actor(User $actor): User
    {
        $current = $actor->exists && is_int($actor->getKey()) && $actor->getKey() > 0 ? User::query()->lockForUpdate()->find($actor->getKey()) : null;
        if ($current === null) {
            throw new AuthorizationException;
        }
        Gate::forUser($current)->authorize('administer-catalog', [true]);
        if (! AdminMultiFactor::satisfiedBy($current, lockForUpdate: true)) {
            throw new AuthorizationException('Admin multi-factor authentication is required.');
        }
        $this->assertActorUnchanged($current);

        return $current;
    }

    private function assertActorUnchanged(User $actor): void
    {
        $row = $this->rawRows('users', ['id' => (int) $actor->id])[0] ?? null;
        if ($row === null || ! $row['is_admin'] || $row['email_verified_at'] === null
            || ! hash_equals(CanonicalJson::hash($row), $this->actorHash($actor))) {
            throw new AuthorizationException('Current operator authority changed. Reopen the editor.');
        }
    }

    private function finalUnchanged(User $actor, ?array $state): void
    {
        $this->assertActorUnchanged($actor);
        if ($state !== null && ! hash_equals($this->stateHash($state), $this->stateHash($this->state((int) $state['draft']['id'])))) {
            $this->reject();
        }
        $this->assertActorUnchanged($actor);
    }

    private function actorHash(User $actor): string
    {
        return CanonicalJson::hash($actor->getRawOriginal());
    }

    private function stateHash(array $state): string
    {
        return CanonicalJson::hash(array_intersect_key($state, array_flip(['draft', 'versions', 'audits'])));
    }

    private function sign(array $review): string
    {
        return hash_hmac('sha256', CanonicalJson::encode($review), app('encrypter')->getKey());
    }

    private function draftTable(): string
    {
        $class = $this->draftClass();

        return (new $class)->getTable();
    }

    /** Current primary-connection reads without model or QueryExecuted observers. */
    private function rawRows(string $table, array $where, string $order = 'id'): array
    {
        $connection = DB::connection();
        $grammar = $connection->getQueryGrammar();
        $conditions = array_map(fn (string $column): string => $grammar->wrap($column).' = ?', array_keys($where));
        $sql = 'SELECT * FROM '.$grammar->wrapTable($table).' WHERE '.implode(' AND ', $conditions).' ORDER BY '.$grammar->wrap($order)
            .($connection->getDriverName() === 'mysql' ? ' FOR UPDATE' : '');
        $statement = $connection->getPdo()->prepare($sql);
        foreach (array_values($where) as $index => $value) {
            $statement->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->execute();

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    private function versionTable(): string
    {
        $class = $this->versionClass();

        return (new $class)->getTable();
    }

    private function identity(PrivateDraft $draft): int
    {
        if ($draft::class !== $this->draftClass() || ! $draft->exists || ! is_int($draft->id) || $draft->id < 1) {
            $this->reject();
        }

        return $draft->id;
    }

    private function hydrate(array $attributes): PrivateDraft
    {
        $class = $this->draftClass();
        $draft = new $class;
        $draft->setRawAttributes($attributes, true);
        $draft->exists = true;

        return $draft;
    }

    private function auditContext(int $number, int $id, string $hash, ?string $previous, string $review): array
    {
        return ['schema' => 1, 'kind' => $this->format()->kind(), 'version' => $number, 'version_id' => $id,
            'manifest_sha256' => $hash, 'previous_manifest_sha256' => $previous, 'review_hash' => $review];
    }

    private function action(int $number): string
    {
        return 'products.private_'.$this->format()->kind().'.'.($number === 1 ? 'created' : 'version_saved');
    }

    private function hash(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private function transaction(User $actor, Closure $operation): mixed
    {
        if (DB::transactionLevel() !== 0) {
            throw new LogicException('Reviewed private authoring requires its own transaction.');
        }

        return DB::transaction(fn () => $operation($this->actor($actor)));
    }

    private function reject(string $message = self::REOPEN): never
    {
        throw ValidationException::withMessages(['title' => $message]);
    }
}
