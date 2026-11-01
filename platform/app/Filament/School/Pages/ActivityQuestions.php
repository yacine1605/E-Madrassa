<?php

namespace App\Filament\School\Pages;

use App\Filament\School\Pages\Concerns\WritesActivities;
use App\Http\Controllers\ActivityController;
use App\Support\Formats;
use App\Support\SchoolScreen;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * FR-LMS-03, a quiz's questions (`?activity=`): single choice, multiple choice or open, their points
 * making the quiz's total, in an explicit order. Changed on the working copy; the pupils keep the
 * version they were published until the next publication. The choices' rules are the database's.
 */
final class ActivityQuestions extends SchoolingPage
{
    use WritesActivities;

    protected static bool $isDiscovered = true;
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'pedagogy';
    protected static array $permissions = ['lms.activity.manage'];
    protected static ?string $module = 'lms';
    protected static string|array $routeMiddleware = ['module:lms', 'permission:lms.activity.manage'];
    #[Locked] public string $activity = '';
    private ?array $sheetRow = null;

    public function mount(): void
    {
        parent::mount();
        $this->activity = (string) request()->query('activity', '');
        abort_unless($this->sheet()['kind'] === 'quiz', 404);
    }

    private function sheet(): array
    {
        abort_unless(Str::isUuid($this->activity), 404);
        return $this->sheetRow ??= SchoolScreen::call(static::$permissions, ActivityController::class, 'show', parameters: ['activity' => $this->activity])['data'];
    }

    public function getTitle(): string { return __('pedagogy.questions').' · '.$this->sheet()['title']; }

    public function getSubheading(): ?string
    {
        $total = array_sum(array_map(fn (array $question) => (float) $question['points'], $this->sheet()['questions']));
        return __('pedagogy.questions_total', ['total' => Formats::number($total, 2), 'max' => Formats::number((float) $this->sheet()['max_points'], 2)]);
    }

    protected function getHeaderActions(): array
    {
        return [Action::make('back')->label(__('pedagogy.back_to_activity'))->color('gray')->url(fn () => ActivitySheet::getUrl(['activity' => $this->activity]))];
    }

    private function edits(): bool { return $this->writesActivities() && $this->sheet()['archived_at'] === null; }

    public function table(Table $table): Table
    {
        return $table->paginated(false)->records(fn () => collect($this->sheet()['questions'])->keyBy('id')->all())
            ->description(__('pedagogy.questions_help'))->emptyStateHeading(__('pedagogy.no_question'))
            ->columns([
                TextColumn::make('position')->label(__('pedagogy.number'))->formatStateUsing(fn ($state) => Formats::number((int) $state)),
                TextColumn::make('prompt')->label(__('pedagogy.prompt'))->wrap()->limit(200)
                    ->description(fn (array $record) => Formats::list(array_map(fn (array $c) => ($c['correct'] ? '✓ ' : '').$c['text'], $record['choices'] ?? [])) ?: null),
                TextColumn::make('kind')->label(__('pedagogy.kind'))->badge()->formatStateUsing(fn ($state) => __('pedagogy.question_kinds.'.$state)),
                TextColumn::make('points')->label(__('pedagogy.points'))->formatStateUsing(fn ($state) => Formats::number((float) $state, 2)),
            ])
            ->headerActions([
                Action::make('add')->label(__('pedagogy.add_question'))->visible(fn () => $this->edits())->modalWidth('2xl')
                    ->schema($this->questionFields(true))
                    ->action(fn (array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'storeQuestion',
                        $this->sentQuestion($data), ['activity' => $this->activity]))),
            ])
            ->recordActions([ActionGroup::make([
                Action::make('edit')->label(__('school.edit'))->visible(fn () => $this->edits())->modalWidth('2xl')
                    ->fillForm(fn (array $record) => [...$record, 'choices' => $record['choices'] ?? []])
                    ->schema($this->questionFields(false))
                    ->action(fn (array $record, array $data) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'updateQuestion',
                        [...$this->sentQuestion([...$data, 'kind' => $record['kind']]), 'version' => $record['version']], ['question' => $record['id']]))),
                Action::make('up')->label(__('pedagogy.move_up'))->color('gray')->visible(fn (array $record) => $this->edits() && $record['position'] > 1)
                    ->action(fn (array $record) => $this->move($record['id'], -1)),
                Action::make('down')->label(__('pedagogy.move_down'))->color('gray')
                    ->visible(fn (array $record) => $this->edits() && $record['position'] < count($this->sheet()['questions']))
                    ->action(fn (array $record) => $this->move($record['id'], 1)),
                Action::make('remove')->label(__('pedagogy.remove_question'))->color('danger')->requiresConfirmation()->visible(fn () => $this->edits())
                    ->action(fn (array $record) => $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'destroyQuestion',
                        ['version' => $record['version']], ['question' => $record['id']]))),
            ])]);
    }

    /** A question's kind (only when it is added), prompt, points and choices, one or several of them correct. */
    private function questionFields(bool $create): array
    {
        $choices = fn (Get $get) => $get('kind') !== 'open';
        return [
            Radio::make('kind')->label(__('pedagogy.kind'))->required()->inline()->default('single')->options(__('pedagogy.question_kinds'))->live()
                ->disabled(!$create)->dehydrated($create),
            Textarea::make('prompt')->label(__('pedagogy.prompt'))->required()->maxLength(4000)->rows(3),
            TextInput::make('points')->label(__('pedagogy.points'))->required()->regex('/^\d{1,5}([.,]\d{1,2})?$/'),
            Repeater::make('choices')->label(__('pedagogy.choices'))->visible($choices)->minItems(2)->maxItems(10)->defaultItems(2)->reorderable(false)
                ->helperText(__('pedagogy.choices_help'))->columns(['default' => 1, 'sm' => 4])
                ->schema([
                    Hidden::make('id'),
                    TextInput::make('text')->label(__('pedagogy.choice'))->required()->maxLength(500)->columnSpan(['sm' => 3]),
                    Toggle::make('correct')->label(__('pedagogy.correct'))->inline(false),
                ]),
        ];
    }

    /** As the API takes it: points with a point, each choice an identifier kept or made (« a », « b »…). */
    private function sentQuestion(array $data): array
    {
        $data['points'] = str_replace(',', '.', trim((string) $data['points']));
        if (($data['kind'] ?? null) === 'open') {
            $data['choices'] = [];
        } else {
            // Ten choices at most: a letter each, a choice kept keeping its own.
            $free = array_values(array_diff(range('a', 'j'), array_filter(array_column($data['choices'] ?? [], 'id'))));
            $data['choices'] = array_values(array_map(function (array $choice) use (&$free) {
                $id = blank($choice['id'] ?? null) ? array_shift($free) : $choice['id'];
                return ['id' => (string) $id, 'text' => (string) $choice['text'], 'correct' => (bool) ($choice['correct'] ?? false)];
            }, $data['choices'] ?? []));
        }
        if (array_key_exists('kind', $data) && $data['kind'] === null) unset($data['kind']);
        return $data;
    }

    /** A question one place up or down, the others kept in their order. */
    private function move(string $question, int $by): void
    {
        $order = array_column($this->sheet()['questions'], 'id');
        $at = array_search($question, $order, true);
        [$order[$at], $order[$at + $by]] = [$order[$at + $by], $order[$at]];
        $this->changed(fn () => SchoolScreen::call(static::$permissions, ActivityController::class, 'orderQuestions', ['questions' => $order], ['activity' => $this->activity]));
    }

    private function changed(callable $change): void
    {
        SchoolScreen::change(null, $change);
        $this->sheetRow = null;
    }
}
