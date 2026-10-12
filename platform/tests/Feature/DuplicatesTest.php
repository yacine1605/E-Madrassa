<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Probable duplicates through the API: pupils across alphabets (FR-ELV-04), guardians typed again (FR-ELV-02). */
final class DuplicatesTest extends TestCase
{
    private function manager(): object
    {
        $manager = $this->account('duplicates');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr']);
        $this->signIn($manager)->assertOk();
        return $school;
    }

    public function test_a_pupil_in_the_other_alphabet_is_a_duplicate_with_the_same_name_and_birth_date_only(): void
    {
        $this->manager();
        $amine = $this->postJson('/api/students', ['last_name_lat' => 'Mohamed', 'first_name_lat' => 'Amine', 'birth_date' => '2014-02-03', 'phone' => '0550 11 22 33'])
            ->assertCreated()->json('data.id');
        // FR-ELV-04 AC1: « Mohamed Amine » and « محمد أمين », born the same day.
        $arabic = ['last_name_ar' => 'محمد', 'first_name_ar' => 'أمين', 'birth_date' => '2014-02-03'];
        $this->postJson('/api/students/duplicates', $arabic)->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $amine)->assertJsonPath('data.0.match', 'other_alphabet');
        $this->postJson('/api/students', $arabic)->assertConflict()->assertJsonPath('code', 'student_duplicate')
            ->assertJsonPath('duplicates.0.match', 'other_alphabet');
        // Another child born the same day is no duplicate: only the name in the other alphabet is.
        $this->postJson('/api/students/duplicates', ['last_name_ar' => 'منصوري', 'first_name_ar' => 'ياسين', 'birth_date' => '2014-02-03'])
            ->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/students', ['last_name_ar' => 'منصوري', 'first_name_ar' => 'ياسين', 'birth_date' => '2014-02-03'])->assertCreated();
        // The same name another day neither; the same phone always.
        $this->postJson('/api/students/duplicates', [...$arabic, 'birth_date' => '2014-02-04'])->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/students/duplicates', ['last_name_lat' => 'Kaci', 'first_name_lat' => 'Nour', 'birth_date' => '2015-01-01', 'phone' => '0550112233'])
            ->assertOk()->assertJsonPath('data.0.id', $amine)->assertJsonPath('data.0.match', 'phone');
        // In the same alphabet, the name as the search reads it: case, accents, spaces and order aside.
        $this->postJson('/api/students/duplicates', ['last_name_lat' => 'AMINE', 'first_name_lat' => 'mohamed', 'birth_date' => '2014-02-03'])
            ->assertOk()->assertJsonPath('data.0.match', 'name');
    }

    public function test_a_guardian_typed_again_is_found_by_name_and_phone_or_email_and_a_households_phone_is_no_duplicate(): void
    {
        $school = $this->manager();
        $karim = $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0550 12 34 56', 'email' => 'karim@example.dz'])
            ->assertCreated()->json('data.id');
        // FR-ELV-02: the father of a second child, typed again.
        $again = ['last_name_lat' => 'BENALI', 'first_name_lat' => 'Karim', 'phone' => '0550123456'];
        $this->postJson('/api/guardians/duplicates', $again)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $karim)
            ->assertJsonPath('data.0.match', 'phone')->assertJsonMissingPath('data.0.external_ref');
        $this->postJson('/api/guardians', $again)->assertConflict()->assertJsonPath('code', 'guardian_duplicate')->assertJsonPath('duplicates.0.id', $karim);
        // In Arabic letters, with the same e-mail.
        $this->postJson('/api/guardians', ['last_name_ar' => 'بن علي', 'first_name_ar' => 'كريم', 'email' => 'KARIM@example.dz'])
            ->assertConflict()->assertJsonPath('code', 'guardian_duplicate')->assertJsonPath('duplicates.0.match', 'email');
        // The mother on the household's phone, a namesake on another phone: other people.
        $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Mina', 'phone' => '0550123456'])->assertCreated();
        $this->postJson('/api/guardians', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Karim', 'phone' => '0661 00 00 00'])->assertCreated();
        // Created anyway with a reason, which the journal keeps.
        $second = $this->postJson('/api/guardians', [...$again, 'duplicate_reason' => 'Homonyme : le grand-père'])->assertCreated()->json('data.id');
        $this->assertSame('Homonyme : le grand-père', DB::connection('owner')->selectOne("SELECT reason FROM school.audit_log
            WHERE tenant_id = ? AND object_id = ? AND action = 'INSERT'", [$school->id, $second])->reason);
        $this->assertSame(4, DB::connection('owner')->table('school.guardian')->where('tenant_id', $school->id)->count());
    }
}
