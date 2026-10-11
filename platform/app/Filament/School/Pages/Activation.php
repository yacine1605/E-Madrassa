<?php

namespace App\Filament\School\Pages;

/** FR-IAM-01: the invitation link opens the account, with its first password unless the person has one already. */
final class Activation extends LinkPage
{
    public function purpose(): string { return 'activation'; }

    public function getTitle(): string { return __('account.activation'); }
}
