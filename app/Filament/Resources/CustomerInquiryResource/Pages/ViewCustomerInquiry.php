<?php

namespace App\Filament\Resources\CustomerInquiryResource\Pages;

use App\Domain\Inquiries\InquiryAdministration;
use App\Domain\Inquiries\InquiryConversation;
use App\Domain\Inquiries\InquiryException;
use App\Filament\Resources\CustomerInquiryResource;
use App\Support\SupportAttachmentUi;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Throwable;

final class ViewCustomerInquiry extends ViewRecord
{
    protected static string $resource = CustomerInquiryResource::class;

    #[Locked]
    public ?string $replyRequestKey = null;

    #[Locked]
    public ?string $replyMessage = null;

    public function boot(): void
    {
        app(InquiryAdministration::class)->actor(Filament::auth()->user());
    }

    public function mount(int|string $record): void
    {
        parent::mount($record);
        $this->record = app(InquiryAdministration::class)->view($this->getRecord()->id, Filament::auth()->user());
    }

    protected function resolveRecord(int|string $key): Model
    {
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::resolveRecord($key));
    }

    public function getRecord(): Model
    {
        return app(InquiryAdministration::class)->authorizedRead(Filament::auth()->user(), fn () => parent::getRecord());
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('reply')->label('Reply in app')->modalHeading('Reply in app')->modalSubmitActionLabel('Save reply')
            ->modalDescription('This reply is visible only to the original browser session in this store. No email is sent. If saving is uncertain, retry the same reply before starting a new one.')
            ->visible(fn (): bool => $this->getRecord()->state !== 'archived')
            ->schema([Textarea::make('message')->label('Reply message')->required()->maxLength(4000)->rows(6)])
            ->mountUsing(function (): void {
                $this->replyRequestKey = (string) Str::uuid();
                $this->replyMessage = null;
            })
            ->action(function (array $data): void {
                if ($this->replyRequestKey === null || ($this->replyMessage !== null && $data['message'] !== $this->replyMessage)) {
                    throw ValidationException::withMessages(['message' => 'Retry the same reply text while its outcome is uncertain.']);
                }
                try {
                    $body = InquiryConversation::validate(['message' => $data['message'], 'requestKey' => $this->replyRequestKey]);
                    $this->replyMessage = $body['message'];
                    app(InquiryConversation::class)->reply($this->getRecord()->id, $body, Filament::auth()->user());
                } catch (InquiryException $error) {
                    throw ValidationException::withMessages(['message' => $error->status === 422 ? 'Enter a plain-text reply of at most 4000 characters.' : 'This reply could not be saved. Refresh the conversation before starting another reply.']);
                } catch (AuthorizationException $error) {
                    throw $error;
                } catch (Throwable) {
                    throw ValidationException::withMessages(['message' => 'Saving could not be confirmed. Retry this exact reply; it will not be duplicated.']);
                }
                $this->replyRequestKey = null;
                $this->replyMessage = null;
                Notification::make()->success()->title('Reply saved in app')->body('No email was sent.')->send();
            }), CustomerInquiryResource::stateAction('markRead', 'Mark read', 'read'), CustomerInquiryResource::stateAction('archive', 'Archive', 'archived'),
            Action::make('attachments')->label('Private attachments')->visible(fn (): bool => SupportAttachmentUi::enabled())
                ->url(fn (): string => '/private-support/operator/inquiries/'.$this->getRecord()->public_id.'/attachments/view')];
    }
}
