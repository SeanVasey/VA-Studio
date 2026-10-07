<?php

namespace App\Filament\Resources\FreeDefinitionResource\Pages;

use App\Domain\Grants\Free\FreeGrantDefinitions;
use App\Domain\Grants\Free\FreeGrantException;
use App\Domain\Grants\Free\Models\FreeDefinition;
use App\Filament\Resources\FreeDefinitionResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

final class ManageFreeDefinitions extends ManageRecords
{
    protected static string $resource = FreeDefinitionResource::class;

    #[Locked]
    public ?array $captured = null;

    #[Locked]
    public ?string $requestKey = null;

    public function boot(): void
    {
        FreeDefinitionResource::actor();
    }

    public function capture(?FreeDefinition $record = null): void
    {
        $this->captured = $record === null ? null : (new FreeGrantDefinitions)->readStaff($record->public_id, FreeDefinitionResource::actor());
        $this->requestKey = (string) Str::uuid();
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('authorFree')->label('Author explicit free definition')->databaseTransaction(false)
            ->modalDescription('Local/testing preparation only. Enter explicit source, purpose text and retrieval limits. This creates an immutable draft; a separate operator must approve the exact free scope before opening requests. No prices, terms or limits are inferred.')
            ->mountUsing(fn () => $this->capture())->schema(FreeDefinitionResource::fields())
            ->action(fn (array $data) => $this->authorFree($data))];
    }

    public function authorFree(array $data): void
    {
        $this->command(function () use ($data): void {
            FreeGrantException::require($this->requestKey !== null, 409);
            foreach (['licenseId', 'trackId', 'scopeId', 'maxOrigins', 'maxDownloads', 'tokenTtlSeconds'] as $field) {
                $data[$field] = $this->integer($data[$field] ?? null);
            }
            FreeGrantException::require(is_array($data['assetIds'] ?? null), 422);
            $data['assetIds'] = array_map($this->integer(...), $data['assetIds']);
            (new FreeGrantDefinitions)->author($data + ['requestKey' => $this->requestKey, 'freePurpose' => 'free-license-grant'], FreeDefinitionResource::actor());
        });
    }

    public function reviewFree(array $data): void
    {
        $this->command(function () use ($data): void {
            FreeGrantException::require($this->captured !== null && $this->requestKey !== null, 409);
            (new FreeGrantDefinitions)->review($this->captured['id'], $data + ['requestKey' => $this->requestKey, 'definitionHash' => $this->captured['definitionHash']], FreeDefinitionResource::actor());
        });
    }

    public function availability(bool $open, array $data): void
    {
        $this->command(function () use ($open, $data): void {
            FreeGrantException::require($this->captured !== null && $this->requestKey !== null && array_keys($data) === ['reason'], 409);
            (new FreeGrantDefinitions)->availability($this->captured['id'], ['requestKey' => $this->requestKey, 'expectedVersion' => $this->captured['version'], 'open' => $open, 'reason' => $data['reason']], FreeDefinitionResource::actor());
        });
    }

    private function integer(mixed $value): int
    {
        FreeGrantException::require(is_int($value) || is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1 && (string) (int) $value === $value, 422);

        return (int) $value;
    }

    private function command(callable $operation): void
    {
        try {
            $operation();
            Notification::make()->title('Free definition saved')->success()->send();
        } catch (Throwable $error) {
            if (! $error instanceof FreeGrantException) {
                try {
                    Log::error('Free definition command failed.', ['exception_class' => $error::class]);
                } catch (Throwable) {
                }
            }
            // Keep this mounted request key for an explicit exact retry; never flash source or exception details.
            throw ValidationException::withMessages(['title' => 'The free definition result is unavailable or changed. Inspect saved definitions before retrying this exact request.']);
        }
    }
}
