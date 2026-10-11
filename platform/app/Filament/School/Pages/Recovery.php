<?php

namespace App\Filament\School\Pages;

/** FR-IAM-02: a forgotten password, a link by email valid one hour, then a new password. */
final class Recovery extends LinkPage
{
    public function purpose(): string { return 'recovery'; }

    public function getTitle(): string { return __('account.recovery'); }
}
