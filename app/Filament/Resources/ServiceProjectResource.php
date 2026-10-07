<?php

namespace App\Filament\Resources;

use App\Domain\Services\Projects\Models\ServiceProject;
use App\Domain\Services\Projects\ServiceProjects;
use App\Filament\Resources\ServiceProjectResource\Pages\ListServiceProjects;
use App\Filament\Resources\ServiceProjectResource\Pages\ViewServiceProject;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

final class ServiceProjectResource extends OperatorResource
{
    protected static ?string $model = ServiceProject::class;

    protected static ?string $slug = 'service-projects';

    protected static string|UnitEnum|null $navigationGroup = 'Customer support';

    public static function canAccess(): bool
    {
        try {
            app(ServiceProjects::class)->staffActor(Filament::auth()->user());

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        if (! in_array($action, ['viewAny', 'view'], true)) {
            return Response::deny();
        }
        app(ServiceProjects::class)->staffActor(Filament::auth()->user());

        return Response::allow();
    }

    public static function getEloquentQuery(): Builder
    {
        app(ServiceProjects::class)->staffActor(Filament::auth()->user());

        return parent::getEloquentQuery();
    }

    public static function getRecordRouteKeyName(): ?string
    {
        return 'public_id';
    }

    public static function snapshot(ServiceProject $record): array
    {
        return app(ServiceProjects::class)->staffShow($record->public_id, Filament::auth()->user());
    }

    public static function table(Table $table): Table
    {
        return $table->description('Synthetic service briefs and scope journeys. Quote acceptance does not collect payment or authorize files.')
            ->columns([TextColumn::make('public_id')->label('Project')->copyable(),
                TextColumn::make('service')->state(fn (ServiceProject $record): string => self::snapshot($record)['title']),
                TextColumn::make('journey')->state(fn (ServiceProject $record): string => str_replace('_', ' ', self::snapshot($record)['status']))->badge(),
                TextColumn::make('created_at')->dateTime('Y-m-d H:i:s', 'UTC')->sortable()])
            ->defaultSort('id', 'desc')->paginated([10, 25])->defaultPaginationPageOption(25)
            ->recordActions([ViewAction::make()])->toolbarActions([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('project')->state(fn (ServiceProject $record): string => self::snapshot($record)['id']),
            TextEntry::make('service')->state(fn (ServiceProject $record): string => self::snapshot($record)['title']),
            TextEntry::make('journey')->state(fn (ServiceProject $record): string => str_replace('_', ' ', self::snapshot($record)['status'])),
            TextEntry::make('brief')->state(function (ServiceProject $record): string {
                $project = self::snapshot($record);

                return $project['summary']."\n\n".implode("\n\n", array_map(fn ($answer): string => $answer['question']."\n".$answer['answer'], $project['answers']));
            })->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-wrap']),
            TextEntry::make('quotes')->label('Retained authored quotes')->state(function (ServiceProject $record): string {
                $project = self::snapshot($record);

                return implode("\n\n", array_map(fn ($quote): string => $quote['title']."\n".$quote['scope']."\n".$quote['currency'].' '.$quote['totalMinor'].' minor units; deposit '.$quote['depositMinor'].' minor units (uncollected).'
                    ."\nRevision allowance: ".$quote['revisionAllowance']."\nCancellation text: ".$quote['cancellation']."\n"
                    .implode("\n", array_map(fn ($milestone): string => $milestone['label'].': '.$milestone['scope'].' — '.($project['milestones'][$milestone['id']] ?? 'not accepted'), $quote['milestones'])), $project['quotes'])) ?: 'No authored quote yet.';
            })->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-wrap']),
            TextEntry::make('history')->state(fn (ServiceProject $record): string => implode("\n", array_map(fn ($event): string => $event['at'].' · '.$event['actor'].' · '.str_replace('_', ' ', $event['action']).($event['reason'] ? ' · '.$event['reason'] : ''), self::snapshot($record)['history'])) ?: 'Brief submitted; awaiting authored quote.')
                ->columnSpanFull()->extraAttributes(['class' => 'whitespace-pre-wrap']),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListServiceProjects::route('/'), 'view' => ViewServiceProject::route('/{record}')];
    }
}
