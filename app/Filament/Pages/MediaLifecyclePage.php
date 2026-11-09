<?php

namespace App\Filament\Pages;

use App\Domain\Media\ReadStaffMediaInventory;
use App\Support\Access\AdminMultiFactor;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Read-only media lifecycle view (FP-034 tier 1): family counts and recorded sizes per state, upload sessions, the bounded
 * dry-run proof of unattached completed media (run only by the explicit Run proof action, throttled and cached per user) and
 * the existing asset-page inventory. Gated like the media asset pages:
 * the catalog gate and enrolled MFA on every Livewire request, and the domain read re-checks both under its own lock.
 * It shows ids, roles, states and recorded sizes only: no file name, storage path, track title or customer value.
 */
final class MediaLifecyclePage extends Page
{
    protected static ?string $slug = 'media-lifecycle';

    protected static ?string $navigationLabel = 'Media lifecycle';

    protected static ?string $title = 'Media lifecycle (read-only)';

    protected string $view = 'filament.media.lifecycle';

    /** Cached proof results live this long per staff user. */
    public const PROOF_TTL_SECONDS = 600;

    /** One proof per staff user per this many seconds. */
    public const PROOF_COOLDOWN_SECONDS = 60;

    /** A string, validated on use: a non-numeric value must be a message, never a hydration error. */
    public string $olderThanDays = '30';

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

    /**
     * The expensive full proof runs only here, on an explicit staff action, at most once per cooldown per user. Its result
     * is cached per user; renders and paging read that cache and the cheap counts only.
     */
    public function runProof(): void
    {
        $days = $this->days();
        if ($days === null) {
            Notification::make()->title('Proof not run')->body(self::AGE_MESSAGE)->danger()->send();

            return;
        }
        $key = 'media-lifecycle-proof:'.auth()->id();
        if (RateLimiter::tooManyAttempts($key, 1)) {
            Notification::make()->title('Proof not run')->body('A proof ran recently. Try again in '.RateLimiter::availableIn($key).' seconds.')->warning()->send();

            return;
        }
        RateLimiter::hit($key, self::PROOF_COOLDOWN_SECONDS);
        try {
            $proof = app(ReadStaffMediaInventory::class)->proveUnattached(auth()->user(), $days);
        } catch (AuthorizationException) {
            abort(403);
        }
        Cache::put(self::cacheKey(), ['days' => $days, 'at' => now()->toIso8601String(), 'proof' => $proof], self::PROOF_TTL_SECONDS);
    }

    private const AGE_MESSAGE = 'Enter a whole number of days from 1 to 3650.';

    private static function cacheKey(): string
    {
        return 'media-lifecycle-proof-result:'.auth()->id();
    }

    private function days(): ?int
    {
        $days = preg_match('/\A[0-9]{1,4}\z/D', $this->olderThanDays) === 1 ? (int) $this->olderThanDays : 0;

        return $days >= 1 && $days <= 3650 ? $days : null;
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $days = $this->days();
        if ($days === null) {
            return ['lifecycle' => null, 'page' => null, 'proofAt' => null, 'error' => self::AGE_MESSAGE];
        }
        $cached = Cache::get(self::cacheKey());
        $cached = is_array($cached) && ($cached['days'] ?? null) === $days ? $cached : null;
        $inventory = app(ReadStaffMediaInventory::class);
        try {
            return ['lifecycle' => $inventory->lifecycle(auth()->user(), $days, $cached['proof'] ?? null),
                'page' => $inventory->handle(auth()->user(), $this->afterId, 50), 'proofAt' => $cached['at'] ?? null, 'error' => null];
        } catch (AuthorizationException) {
            abort(403);
        } catch (ValidationException $error) {
            return ['lifecycle' => null, 'page' => null, 'proofAt' => null, 'error' => collect($error->errors())->flatten()->first()];
        }
    }
}
