<?php

namespace App\Filament\Resources\ServiceProjectResource\Pages;

use App\Domain\Services\Projects\ServiceProjectInput;
use App\Domain\Services\Projects\ServiceProjects;
use App\Filament\Resources\ServiceProjectResource;
use App\Support\CanonicalJson;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

final class ViewServiceProject extends ViewRecord
{
    protected static string $resource = ServiceProjectResource::class;

    #[Locked]
    public ?int $commandVersion = null;

    #[Locked]
    public ?string $commandKey = null;

    #[Locked]
    public ?string $commandAction = null;

    #[Locked]
    public ?string $enteredHash = null;

    public function boot(): void
    {
        app(ServiceProjects::class)->staffActor(Filament::auth()->user());
    }

    private function snapshot(): array
    {
        return ServiceProjectResource::snapshot($this->getRecord());
    }

    protected function getHeaderActions(): array
    {
        $quote = $this->action('author_quote', 'Author test quote')
            ->visible(fn (): bool => in_array($this->snapshot()['status'], ['submitted', 'quoted', 'declined'], true))
            ->modalDescription('Supply every term and amount. The buyer must explicitly accept this exact retained quote. No charge, deposit collection or delivery is authorized.')
            ->modalSubmitActionLabel('Send authored test quote')
            ->schema([TextInput::make('title')->required()->maxLength(180), Textarea::make('scope')->required()->maxLength(4000),
                TextInput::make('currency')->label('Currency (three uppercase letters)')->required()->length(3),
                TextInput::make('totalMinor')->label('Total in integer minor units')->integer()->required()->minValue(1)->maxValue(2147483647),
                TextInput::make('depositMinor')->label('Requested deposit in minor units (uncollected)')->integer()->required()->minValue(0)->maxValue(2147483647),
                TextInput::make('revisionAllowance')->label('Included revisions')->integer()->required()->minValue(0)->maxValue(20),
                Textarea::make('cancellation')->label('Supplied cancellation terms')->required()->maxLength(2000),
                Repeater::make('milestones')->schema([TextInput::make('id')->label('Stable milestone ID')->required()->maxLength(40),
                    TextInput::make('label')->required()->maxLength(180), Textarea::make('scope')->required()->maxLength(2000)])
                    ->minItems(1)->maxItems(10)->defaultItems(1)->reorderableWithButtons(),
                Checkbox::make('confirmed')->label('I reviewed the scope, exact amounts, milestones and cancellation text.')->accepted()->required()]);
        $begin = $this->milestone('begin_milestone', 'Begin milestone', 'pending');
        $ready = $this->milestone('ready_milestone', 'Ready for customer review', 'active');
        $cancel = $this->action('cancel', 'Record cancellation')->visible(fn (): bool => ! in_array($this->snapshot()['status'], ['cancelled', 'withdrawn'], true))
            ->modalDescription('Record cancellation in this scope journey. Payment, refunds, existing rights and delivered files require their separate approved workflows.')
            ->schema([Textarea::make('reason')->required()->maxLength(2000)]);

        return [$quote, $begin, $ready, $cancel];
    }

    private function milestone(string $action, string $label, string $state): Action
    {
        return $this->action($action, $label)->visible(fn (): bool => in_array($this->snapshot()['status'], ['accepted', 'in_progress', 'awaiting_customer_review'], true)
                && in_array($state, $this->snapshot()['milestones'], true))
            ->schema([Select::make('milestoneId')->label('Milestone')->options(function () use ($state): array {
                $project = $this->snapshot();
                $quote = collect($project['quotes'])->firstWhere('id', $project['quoteId']);
                $options = [];
                foreach ($quote['milestones'] ?? [] as $milestone) {
                    if (($project['milestones'][$milestone['id']] ?? null) === $state) {
                        $options[$milestone['id']] = $milestone['label'];
                    }
                }

                return $options;
            })->required(), Textarea::make('reason')->label('Status note')->required()->maxLength(2000)]);
    }

    private function action(string $name, string $label): Action
    {
        return Action::make($name)->label($label)->mountUsing(function () use ($name): void {
            $this->commandVersion = $this->snapshot()['version'];
            $this->commandKey = (string) Str::uuid();
            $this->commandAction = $name;
            $this->enteredHash = null;
        })->action(function (array $data) use ($name): void {
            if ($this->commandVersion === null || $this->commandKey === null || $this->commandAction !== $name) {
                throw ValidationException::withMessages(['mountedActions.0.data.scope' => 'Close and reopen this action before saving.']);
            }
            if ($name === 'author_quote') {
                if (($data['confirmed'] ?? null) !== true) {
                    throw ValidationException::withMessages(['mountedActions.0.data.confirmed' => 'Review and confirm every supplied term.']);
                }
                unset($data['confirmed']);
                foreach (['totalMinor', 'depositMinor', 'revisionAllowance'] as $field) {
                    if (is_float($data[$field]) && is_finite($data[$field]) && floor($data[$field]) === $data[$field] && $data[$field] >= 0 && $data[$field] <= 2147483647) {
                        $data[$field] = (int) $data[$field];
                    }
                    if (is_string($data[$field]) && preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $data[$field])) {
                        $data[$field] = (int) $data[$field];
                    }
                }
                $extra = ['quote' => $data];
            } else {
                $extra = $data;
            }
            $body = ['requestKey' => $this->commandKey, 'expectedVersion' => $this->commandVersion, 'action' => $name, ...$extra];
            $body = ServiceProjectInput::command($body, true);
            $hash = CanonicalJson::hash($body);
            if ($this->enteredHash !== null && $this->enteredHash !== $hash) {
                throw ValidationException::withMessages(['mountedActions.0.data.scope' => 'An uncertain save requires retrying the exact entered contents.']);
            }
            $this->enteredHash = $hash;
            try {
                app(ServiceProjects::class)->staffCommand($this->getRecord()->public_id, $body, Filament::auth()->user());
            } catch (AuthorizationException $error) {
                throw $error;
            } catch (Throwable) {
                throw ValidationException::withMessages(['mountedActions.0.data.scope' => 'Saving was not confirmed or the project changed. Keep the entered text; close and reopen to inspect the saved journey. An exact retry does not duplicate the same command.']);
            }
            $this->commandVersion = null;
            $this->commandKey = null;
            $this->commandAction = null;
            $this->enteredHash = null;
            Notification::make()->success()->title('Scope journey saved')->body('No payment or delivery was authorized.')->send();
        });
    }
}
