<?php

namespace App\Filament\Pages;

use App\Domain\Media\ReadStaffMediaInventory;
use App\Support\Access\AdminMultiFactor;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Read-only media lifecycle view (FP-034 tier 1): family counts and recorded sizes per state, upload sessions, the bounded
 * dry-run proof of unattached completed media and the existing asset-page inventory. Gated like the media asset pages:
 * the catalog gate and enrolled MFA on every Livewire request, and the domain read re-checks both under its own lock.
 * It shows ids, roles, states and recorded sizes only: no file name, storage path, track title or customer value.
 */
final class MediaLifecyclePage extends Page
{
    protected static ?string $slug = 'media-lifecycle';

    protected static ?string $navigationLabel = 'Media lifecycle';

    protected static ?string $title = 'Media lifecycle (read-only)';

    protected string $view = 'filament.media.lifecycle';

    public int $olderThanDays = 30;

    public int $afterId = 0;

    public static function canAccess(): bool
    {
        return Gate::allows('administer-catalog');
    }

    /** Runs on every Livewire request, including updates to a component mounted before access was removed. */
    public function boot(): void
    {
        Gate::authorize('administer-catalog');
        $user = auth()->user();
        abort_unless($user !== null && AdminMultiFactor::satisfiedBy($user), 403);
    }

    public function nextPage(int $afterId): void
    {
        $this->afterId = max(0, $afterId);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $inventory = app(ReadStaffMediaInventory::class);
        try {
            return ['lifecycle' => $inventory->lifecycle(auth()->user(), $this->olderThanDays),
                'page' => $inventory->handle(auth()->user(), $this->afterId, 50), 'error' => null];
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $error) {
            return ['lifecycle' => null, 'page' => null, 'error' => collect($error->errors())->flatten()->first()];
        }
    }
}
