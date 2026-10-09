<?php

namespace App\Console\Commands;

use App\Domain\Catalog\Models\Offer;
use App\Domain\Catalog\Models\OfferRevision;
use App\Domain\Commerce\Inventory\InventoryPolicy;
use App\Domain\Commerce\Inventory\ManageRightsScope;
use App\Domain\Commerce\Models\RightsScope;
use App\Domain\Commerce\Models\RightsScopeOffer;
use App\Domain\Commerce\QuoteException;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Hash;
use Throwable;

/**
 * Operator entry point for the test-only shared rights inventory. Every rule (staff authority,
 * environment, key/reference format, readiness, immutable linkage and audit) stays in
 * ManageRightsScope; this command only authenticates the named staff account and maps outcomes.
 */
final class ManageRightsScopes extends Command
{
    protected $signature = 'vasey:rights-scope
        {action : register, link or list}
        {--actor-id= : Staff user ID performing a write; the password is confirmed at a hidden prompt}
        {--scope= : Rights scope key (lowercase letters, digits and . _ : -)}
        {--revision= : Offer revision ID to link, as printed by the list action}
        {--reference= : Private evidence reference retained with the scope or link (never printed)}';

    protected $description = 'Register test rights scopes and link current offer revisions to them through the audited domain service.';

    private const ALLOWED = [
        'register' => ['actor-id', 'scope', 'reference'],
        'link' => ['actor-id', 'scope', 'revision', 'reference'],
        'list' => [],
    ];

    public function handle(): int
    {
        $action = $this->argument('action');
        $invalid = $this->invalidInput($action);
        if ($invalid !== null) {
            return $this->refuse('Invalid input: '.$invalid, self::INVALID);
        }
        try {
            if ($action === 'list') {
                return $this->list();
            }
            if (! $this->input->isInteractive()) {
                return $this->refuse('Rights scope writes require an interactive trusted console to confirm the staff password.');
            }
            $actor = $this->authenticate();
            if ($actor === null) {
                return $this->refuse('Operator authority was refused.');
            }

            return $action === 'register' ? $this->register($actor) : $this->link($actor);
        } catch (AuthorizationException) {
            return $this->refuse('Operator authority was refused.');
        } catch (QuoteException $error) {
            return $this->domainRefusal($error);
        } catch (Throwable) {
            if ($action === 'list') {
                $this->error('Rights scope listing is unavailable.');
            } else {
                $this->error('Rights scope management is unavailable. The result is unconfirmed. '
                    .'Inspect with list and retry the exact original request, preserving its scope, revision and reference.');
            }

            return self::FAILURE;
        }
    }

    private function invalidInput(mixed $action): ?string
    {
        if (! is_string($action) || ! array_key_exists($action, self::ALLOWED)) {
            return 'the action must be register, link or list.';
        }
        foreach (['actor-id', 'scope', 'revision', 'reference'] as $name) {
            $value = $this->option($name);
            $allowed = in_array($name, self::ALLOWED[$action], true);
            if (! $allowed && $value !== null) {
                return '--'.$name.' does not apply to '.$action.'.';
            }
            if ($allowed && (! is_string($value) || $value === '')) {
                return '--'.$name.' is required for '.$action.'.';
            }
        }
        foreach (['actor-id', 'revision'] as $name) {
            $value = $this->option($name);
            if ($value !== null && preg_match('/\A[1-9][0-9]{0,17}\z/D', $value) !== 1) {
                return '--'.$name.' must be a positive whole-number ID.';
            }
        }

        return null;
    }

    /** Current persisted credentials; authority itself is re-read by the domain inside its transaction. */
    private function authenticate(): ?User
    {
        $password = $this->secret('Staff password (hidden)', false);
        $actor = User::query()->find((int) $this->option('actor-id'));
        $valid = $actor !== null && is_string($password) && $password !== '' && Hash::check($password, $actor->getAuthPassword());
        unset($password);

        return $valid ? $actor : null;
    }

    private function register(User $actor): int
    {
        $key = (string) $this->option('scope');
        $existed = RightsScope::where('scope_key', $key)->exists();
        $scope = app(ManageRightsScope::class)->register($key, (string) $this->option('reference'), $actor);
        $this->line('scope='.$scope->scope_key.' id='.$scope->public_id.' status='.($existed ? 'unchanged' : 'registered'));

        return self::SUCCESS;
    }

    private function link(User $actor): int
    {
        $key = (string) $this->option('scope');
        $revisionId = (int) $this->option('revision');
        // Scope keys are immutable identities (SQL-guarded), so resolving the key before the domain transaction is stable.
        $scope = RightsScope::where('scope_key', $key)->first();
        if (! $scope) {
            return $this->refuse('Rights scope '.$this->safeKey($key).' is not registered. Register it first.');
        }
        try {
            $link = app(ManageRightsScope::class)->link($scope->id, $revisionId, (string) $this->option('reference'), $actor);
        } catch (ModelNotFoundException) {
            return $this->refuse('Offer revision '.$revisionId.' was not found.');
        } catch (QuoteException $error) {
            if ($error->errorCode === 'INVENTORY_SCOPE_CONFLICT') {
                $current = RightsScopeOffer::where('offer_revision_id', $revisionId)->value('rights_scope_id');
                $currentKey = $current === null ? null : RightsScope::whereKey($current)->value('scope_key');
                if ($currentKey !== null && (int) $current !== $scope->id) {
                    return $this->refuse('Refused (INVENTORY_SCOPE_CONFLICT): offer revision '.$revisionId
                        .' is already linked to scope '.$currentKey.'. Links are immutable; publish a new offer revision to link it elsewhere.');
                }
            }
            throw $error;
        }
        $this->line('revision='.$link->offer_revision_id.' scope='.$scope->scope_key
            .' status='.($link->wasRecentlyCreated ? 'linked' : 'unchanged'));

        return self::SUCCESS;
    }

    /** Read-only: registered scopes and current published revisions that still need a link. No evidence references. */
    private function list(): int
    {
        InventoryPolicy::requireTestEnvironment();
        foreach (RightsScope::query()->orderBy('id')->get() as $scope) {
            $this->line('scope='.$scope->scope_key.' id='.$scope->public_id.' blocked='.($scope->blocked ? 'yes' : 'no')
                .' control_version='.$scope->control_version
                .' links='.RightsScopeOffer::where('rights_scope_id', $scope->id)->count());
        }
        $offers = Offer::query()->with('track')->where('is_active', true)->whereNotNull('current_revision_id')
            ->whereNotIn('current_revision_id', RightsScopeOffer::query()->select('offer_revision_id'))
            ->orderBy('id')->get();
        foreach ($offers as $offer) {
            if ($offer->track?->status !== 'published') {
                continue;
            }
            $revision = OfferRevision::find($offer->current_revision_id);
            $this->line('unlinked revision='.$offer->current_revision_id.' offer='.$offer->id.' track='.$offer->track->slug
                .' offer_revision='.($revision?->revision ?? '?'));
        }

        return self::SUCCESS;
    }

    private function domainRefusal(QuoteException $error): int
    {
        if ($error->errorCode === 'INVALID_QUOTE_REQUEST') {
            return $this->refuse('Invalid input: the scope key must be lowercase letters, digits and . _ : - (1-96 characters, starting with a letter or digit), '
                .'and the reference must be letters, digits and . _ : / - (1-192 characters, starting with a letter or digit).', self::INVALID);
        }
        $reason = match ($error->errorCode) {
            'INVENTORY_SCOPE_CONFLICT' => 'the scope key or revision link already exists with a different evidence reference or scope; existing identity is immutable.',
            'SELECTION_CHANGED' => 'the revision is not the current revision of an active offer on a published track that passes publication readiness.',
            'INVENTORY_UNAVAILABLE' => 'rights scopes are available only in the local, testing and staging environments.',
            default => 'the domain service refused the request.',
        };

        return $this->refuse('Refused ('.$error->errorCode.'): '.$reason);
    }

    private function safeKey(string $key): string
    {
        return preg_match('/\A[a-z0-9][a-z0-9._:-]{0,95}\z/D', $key) === 1 ? $key : '(invalid key)';
    }

    private function refuse(string $message, int $code = self::FAILURE): int
    {
        $this->error($message.' No changes were made.');

        return $code;
    }
}
