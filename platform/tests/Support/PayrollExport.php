<?php

namespace Tests\Support;

use App\Exports\Dataset;

/** A dataset with a salary column, for FR-IMP-02 AC1: the column follows its own permission. */
final class PayrollExport implements Dataset
{
    public function permission(): string { return 'referential.node.read'; }

    public function title(string $locale): string { return 'Paie'; }

    public function columns(string $locale): array
    {
        return [['key' => 'name', 'label' => 'Nom'], ['key' => 'salary', 'label' => 'Salaire net', 'permission' => 'payroll.amount.read'],
            ['key' => 'email', 'label' => 'E-mail', 'permission' => 'user.account.read']];
    }

    public function filters(): array { return []; }

    public function count(array $filters): int { return 1; }

    public function rows(array $filters, string $locale): iterable
    {
        yield ['name' => 'Amine K.', 'salary' => 85000, 'email' => 'amine@example.test'];
    }
}
