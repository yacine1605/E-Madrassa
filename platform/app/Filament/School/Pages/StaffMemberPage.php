<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\StaffController;
use App\Support\Formats;
use App\Support\Permissions;
use App\Support\SchoolScreen;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;

/**
 * A screen about one member of staff, opened from their row of the staff list (`?staff=`): their
 * qualifications, their availability, their days off, their contracts and leave. Read with their
 * record (FR-STF-01), and with its own permission where a screen declares one (FR-STF-04).
 */
abstract class StaffMemberPage extends SchoolingPage
{
    protected static bool $shouldRegisterNavigation = false;
    protected static ?string $section = 'staff';
    protected static array $permissions = ['staff.profile.read'];
    protected static ?string $module = 'staff';
    protected static string|array $routeMiddleware = ['module:staff', 'permission:staff.profile.read'];
    #[Locked] public string $staff = '';
    private ?array $staffRow = null;

    /** What the screen shows of the member, before their function, site and state. */
    abstract protected function subject(): string;

    public function mount(): void
    {
        parent::mount();
        $this->staff = (string) request()->query('staff', '');
        $this->member();
    }

    /** The member of staff as the reader may see them; one out of their reach is not found. */
    public function member(): array
    {
        abort_unless(Str::isUuid($this->staff), 404);
        return $this->staffRow ??= SchoolScreen::call('staff.profile.read', StaffController::class, 'show', parameters: ['staff' => $this->staff])['data'];
    }

    public function getTitle(): string
    {
        return $this->personName($this->member()).' · '.$this->member()['staff_number'];
    }

    public function getSubheading(): ?string
    {
        $member = $this->member();
        return Formats::list(array_filter([$this->subject(), __('staff.types.'.$member['staff_type']), $this->siteName($member['site_id']),
            $member['archived_at'] ? __('staff.states.archived') : __('staff.states.'.$member['status'])]));
    }

    /** A teacher, internal or external, still with the school (FR-STF-01). */
    protected function teaching(): bool
    {
        $member = $this->member();
        return $member['status'] !== 'left' && in_array($member['staff_type'], ['teacher_internal', 'teacher_external'], true);
    }

    /** Whoever holds this permission on this member, while the record is in use. */
    protected function changeableWith(string $permission): bool
    {
        return !$this->member()['archived_at'] && $this->writableFor($permission, 'staff', $this->staff);
    }

    /** « 4,5 h »: hours a day or a week, without decimals when whole. */
    protected function hours(int|float|string|null $hours): string
    {
        if ($hours === null) return '';
        $value = (float) $hours;
        return __('staff.hours', ['hours' => Formats::number($value, floor($value) == $value ? 0 : 2)]);
    }

    /** Whether the reader may open another of the member's screens, read with this permission. */
    protected function reads(string $permission): bool { return app(Permissions::class)->can($permission); }

    /** Who declared a pending one; who decided the others, when, and why if said. */
    protected function decision(array $record): string
    {
        if ($record['status'] === 'pending') return __('staff.declared_by', ['name' => $record['declared_by_name'] ?? '']);
        return Formats::list(array_filter([__('staff.decided_by', ['name' => $record['decided_by_name'] ?? '', 'date' => Formats::date($record['decided_at'])]),
            $record['decision_reason']]));
    }
}
