<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\NotificationController;
use App\Support\Formats;
use App\Support\Locales;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** FR-NOT-01 AC1: the emails that failed five times, with their reason, sent again on request. */
final class Deliveries extends SchoolPage implements HasTable
{
    use InteractsWithTable;

    protected static bool $isDiscovered = true;
    protected static array $permissions = ['tenant.settings.manage'];
    protected static ?string $section = 'administration';
    protected static ?int $navigationSort = 105;
    protected static string|array $routeMiddleware = 'permission:tenant.settings.manage';
    protected string $view = 'filament.school.table';

    public static function getNavigationLabel(): string { return __('services.deliveries'); }
    public function getTitle(): string { return __('services.deliveries'); }
    public function getSubheading(): ?string { return __('services.deliveries_help'); }

    public function table(Table $table): Table
    {
        $events = collect(DB::select('SELECT code, labels FROM platform.notification_event'))->mapWithKeys(fn ($event) => [$event->code => Locales::label($event->labels)]);
        return $table->records(fn () => collect(SchoolScreen::call('tenant.settings.manage', NotificationController::class, 'failures')['data'])->keyBy('id')->all())
            ->paginated(false)->columns([
                TextColumn::make('recipient')->label(__('services.recipient')),
                TextColumn::make('event')->label(__('services.notification'))->formatStateUsing(fn (string $state) => $events[$state] ?? $state),
                TextColumn::make('attempts')->label(__('services.attempts'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('last_error')->label(__('services.last_error'))->limit(80),
                TextColumn::make('created_at')->label(__('services.created_at'))->formatStateUsing(fn ($state) => Formats::dateTime($state)),
            ])->recordActions([
                Action::make('resend')->label(__('services.resend'))->requiresConfirmation()->visible(fn () => $this->writable('tenant.settings.manage'))
                    ->action(fn (array $record) => SchoolScreen::change('tenant.settings.manage',
                        fn () => SchoolScreen::call('tenant.settings.manage', NotificationController::class, 'retry', parameters: ['delivery' => $record['id']]))),
            ]);
    }
}
