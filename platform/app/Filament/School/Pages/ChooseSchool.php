<?php

namespace App\Filament\School\Pages;

use App\Http\Controllers\SessionController;
use App\Support\SchoolMemberships;
use App\Support\SchoolScreen;
use Filament\Pages\Page;

final class ChooseSchool extends Page
{
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.school.choose-school';

    public function getTitle(): string { return __('school.choose_school'); }

    public function schools(): array { return app(SchoolMemberships::class)->forUser(auth()->id())->all(); }

    public function choose(string $tenant): void
    {
        // Validation rechecks active memberships, dedicated pin and school state on submission.
        SchoolScreen::payload(app(SessionController::class)->select(SchoolScreen::request(['tenant_id' => $tenant])));
        $this->redirect('/school/home');
    }
}
