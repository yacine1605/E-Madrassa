<?php

namespace Tests\Feature;

use App\Filament\School\Pages\ExpenseCategories;
use App\Filament\School\Pages\Expenses;
use App\Support\CurrentSchool;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Expenses (FR-DEP-01, FR-DEP-02, SQL 055) through the real routes and screens: the school's
 * categories, `salaries` never entered by hand (AC1); an expense entered once per key, documented
 * and submitted by its author, validated by someone else (AC1), by the direction beyond the
 * threshold, never changed afterwards (AC2); its documents never for a teacher (AC3).
 */
final class ExpenseTest extends TestCase
{
    public function test_an_expense_is_entered_submitted_and_decided_by_someone_else(): void
    {
        Storage::fake('files');
        $owner = $this->account('expenses-owner');
        $school = $this->school('pro', $owner, 'private_school_dz');
        $clerk = $this->account('clerk');
        $this->member($school, $clerk, 'active', ['accountant']);
        $validator = $this->account('validator');
        $this->member($school, $validator, 'active', ['accountant']);
        $director = $this->account('director');
        $this->member($school, $director, 'active', ['direction']);
        $teacher = $this->account('teacher');
        $this->member($school, $teacher, 'active', ['teacher']);
        $json = ['Accept' => 'application/json'];

        $this->signIn($clerk)->assertOk();
        $categories = collect($this->getJson('/api/expense-categories')->assertOk()->json('data'))->pluck('id', 'code');
        $this->assertSame(['salaries', 'advertising', 'missions', 'rent', 'equipment', 'other'], $categories->keys()->all());
        $expense = fn (string $category, string $amount, array $extra = []) => $this->postJson('/api/expenses', ['expense_date' => now()->toDateString(),
            'amount' => $amount, 'category_id' => $categories[$category], 'beneficiary' => 'SCI Les Oliviers', 'reason' => 'Loyer du mois',
            'idempotency_key' => (string) Str::uuid(), ...$extra]);
        // FR-DEP-01 AC1: never `salaries` by hand; `other` says what it is.
        $expense('salaries', '1000')->assertConflict()->assertJsonPath('code', 'expense_category_system');
        $expense('other', '1000')->assertConflict()->assertJsonPath('code', 'expense_label_required');
        $key = (string) Str::uuid();
        $lease = $expense('rent', '30000', ['idempotency_key' => $key])->assertCreated()->assertJsonPath('data.status', 'draft')->json('data');
        $expense('rent', '30000', ['idempotency_key' => $key])->assertCreated()->assertJsonPath('data.id', $lease['id']);
        $this->post("/api/expenses/{$lease['id']}/attachments", ['attachment' => UploadedFile::fake()->createWithContent('quittance.pdf', "%PDF-1.4\n%%EOF\n")], $json)
            ->assertCreated()->assertJsonCount(1, 'data.documents');
        $document = $this->getJson("/api/expenses/{$lease['id']}")->json('data.documents.0.id');
        $this->postJson("/api/expenses/{$lease['id']}/submit", ['version' => $lease['version']])->assertOk()->assertJsonPath('data.status', 'submitted')
            ->assertJsonPath('data.needs_approval', false);
        $this->putJson("/api/expenses/{$lease['id']}", ['expense_date' => now()->toDateString(), 'amount' => '1', 'category_id' => $categories['rent'],
            'beneficiary' => 'SCI Les Oliviers', 'reason' => 'Correction', 'version' => $lease['version'] + 1])->assertConflict()->assertJsonPath('code', 'expense_immutable');

        // AC1: validated by someone else; AC2: then never changed.
        $this->signIn($validator)->assertOk();
        $this->get("/api/expenses/{$lease['id']}/attachments/$document")->assertOk()->assertDownload('quittance.pdf');
        $this->postJson("/api/expenses/{$lease['id']}/validate", ['version' => $lease['version'] + 1])->assertOk()->assertJsonPath('data.status', 'validated');
        $own = $this->postJson('/api/expenses', ['expense_date' => now()->toDateString(), 'amount' => '2000', 'category_id' => $categories['missions'],
            'beneficiary' => 'Hôtel Es-Salam', 'reason' => 'Mission à Oran', 'idempotency_key' => (string) Str::uuid()])->assertCreated()->json('data');
        $this->postJson("/api/expenses/{$own['id']}/submit", ['version' => $own['version']])->assertOk();
        $this->postJson("/api/expenses/{$own['id']}/validate", ['version' => $own['version'] + 1])->assertConflict()->assertJsonPath('code', 'segregation_of_duties');
        $this->postJson("/api/expenses/{$lease['id']}/reject", ['version' => $lease['version'] + 2, 'reason' => 'Doublon'])
            ->assertConflict()->assertJsonPath('code', 'expense_immutable');

        // Beyond the threshold (50,000 DA), the direction decides.
        $this->signIn($clerk)->assertOk();
        $laptops = $expense('equipment', '80000', ['beneficiary' => 'Informatique Plus', 'reason' => 'Deux ordinateurs'])->assertCreated()->json('data');
        $this->postJson("/api/expenses/{$laptops['id']}/submit", ['version' => $laptops['version']])->assertOk()->assertJsonPath('data.needs_approval', true);
        $this->signIn($validator)->assertOk();
        $this->postJson("/api/expenses/{$laptops['id']}/validate", ['version' => $laptops['version'] + 1])->assertForbidden();
        $this->signIn($director)->assertOk();
        $this->postJson("/api/expenses/{$laptops['id']}/validate", ['version' => $laptops['version'] + 1])->assertOk()->assertJsonPath('data.status', 'validated');
        $this->getJson('/api/expenses?status=validated')->assertOk()->assertJsonPath('meta.total', 2);
        $this->getJson('/api/expenses?category='.$categories['equipment'].'&from='.now()->toDateString())->assertOk()->assertJsonPath('meta.total', 1);

        // AC3: a teacher sees no expense and no document, even with its address.
        $this->signIn($teacher)->assertOk();
        $this->getJson('/api/expenses')->assertForbidden();
        $this->get("/api/expenses/{$lease['id']}/attachments/$document")->assertForbidden();
        $this->get("/api/files/$document/content")->assertNotFound();
        $this->get('/school/expenses')->assertForbidden();

        // On the screens: a category named, an expense entered and submitted by its author, validated by someone else.
        $within = function (array $account, callable $test) use ($school) {
            $this->actingAs($this->person($account));
            $this->withSession(['school.tenant' => $school->id, 'auth.started_at' => time(), 'auth.seen_at' => time()]);
            Filament::setCurrentPanel(Filament::getPanel('school'));
            DB::transaction(function () use ($school, $account, $test) {
                app(CurrentSchool::class)->enter($school->id, $account['id'], CurrentSchool::requestId(null));
                app(CurrentSchool::class)->setMode('active');
                $test();
            });
        };
        $within($validator, function () {
            Livewire::test(ExpenseCategories::class)->callTableAction('create', data: ['code' => 'transport', 'labels' => ['fr' => 'Transport', 'ar' => 'النقل'],
                'needs_label' => false, 'sort_order' => 70])->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
        });
        $within($clerk, function () use ($categories) {
            Livewire::test(Expenses::class)->callTableAction('create', data: ['amount' => '4 500,50', 'category_id' => $categories['advertising'],
                'beneficiary' => 'Imprimerie du Centre', 'reason' => 'Affiches de rentrée'])->assertHasNoTableActionErrors()->assertNotified(__('school.saved'));
        });
        $flyers = DB::connection('owner')->table('school.expense')->where('tenant_id', $school->id)->where('beneficiary', 'Imprimerie du Centre')->first();
        $this->assertSame('4500.50', $flyers->amount);
        $within($clerk, function () use ($flyers) {
            Livewire::test(Expenses::class)->callTableAction('submit', $flyers->id)->assertNotified(__('school.saved'))
                ->assertTableActionHidden('validate', $flyers->id);
        });
        $within($validator, function () use ($flyers) {
            Livewire::test(Expenses::class)->filterTable('status', 'submitted')->assertCanSeeTableRecords([$flyers->id])
                ->callTableAction('validate', $flyers->id)->assertNotified(__('school.saved'));
        });
        $this->assertSame('validated', DB::connection('owner')->table('school.expense')->where('id', $flyers->id)->value('status'));
        $this->assertSame(1, DB::connection('owner')->table('school.expense_category')->where('tenant_id', $school->id)->where('code', 'transport')->count());
    }
}
