<?php

namespace App\Filament\Pages;

use App\Domain\Media\PreviewTag\PreviewTagPolicy;
use App\Domain\Media\PreviewTag\PreviewTagResolver;
use App\Support\Access\AdminMultiFactor;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;

/**
 * The store-wide default for whether public previews carry the seller tag (Sean, 2026-11-11: default on). Each track can override
 * it from Tracks → Preview tag. Switching changes only which already-processed preview visitors hear, at once; nothing is reprocessed
 * and no offer, license or file changes. The write is {@see PreviewTagPolicy::setDefault()}, which re-checks catalog authority and
 * enrolled MFA under lock and records the audit event; this page only shows the setting and the recorded coverage of untagged variants.
 */
final class PreviewTagSettingsPage extends Page
{
    protected static ?string $slug = 'preview-tag';

    protected static ?string $navigationLabel = 'Preview tag';

    protected static ?string $title = 'Preview tag (store default)';

    protected string $view = 'filament.media.preview-tag';

    /** On: previews carry the seller tag. */
    public bool $defaultTagged = true;

    /** The revision the editor loaded; a different stored revision refuses the save. */
    #[Locked]
    public int $revision = 0;

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

    public function mount(): void
    {
        $this->load();
    }

    public function save(): void
    {
        try {
            app(PreviewTagPolicy::class)->setDefault($this->defaultTagged, $this->revision, auth()->user());
        } catch (ValidationException $exception) {
            Notification::make()->danger()->title('Store default not changed')->body(implode(' ', array_merge(...array_values($exception->errors()))))->persistent()->send();
            $this->load();

            return;
        }
        $this->load();
        Notification::make()->success()->title('Store default saved')->body('Previews now '.($this->defaultTagged ? 'carry' : 'omit').' the seller tag unless a track overrides it.')->send();
    }

    private function load(): void
    {
        $resolver = app(PreviewTagResolver::class);
        $this->defaultTagged = $resolver->defaultTagged();
        $this->revision = $resolver->revision();
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['coverage' => app(PreviewTagResolver::class)->coverage()];
    }
}
