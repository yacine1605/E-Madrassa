<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** A pupil's photo through its routes (FR-ELV-01, its photo rules and AC0; SQL 026). */
final class StudentPhotoTest extends TestCase
{
    private const PLACE = 'GPS 36.7538N 3.0588E Alger';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('files');
    }

    /** @return array{0: object, 1: array} a school and its head of studies, who also manages its settings */
    private function manager(): array
    {
        $manager = $this->account('photos');
        $school = $this->school('essential', $manager, 'private_school_dz');
        $this->member($school, $manager, 'active', ['academic_hr', 'tenant_admin']);
        $this->signIn($manager)->assertOk();
        return [$school, $manager];
    }

    private function pupil(array $extra = []): array
    {
        return $this->postJson('/api/students', ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'birth_date' => '2012-05-15', ...$extra])
            ->assertCreated()->json('data');
    }

    /** Left half red, right half blue: where a turn or a crop sent them shows. */
    private function image(string $type, int $width, int $height, bool $transparent = false): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, intdiv($width, 2) - 1, $height - 1, imagecolorallocate($image, 200, 30, 30));
        imagefilledrectangle($image, intdiv($width, 2), 0, $width - 1, $height - 1, imagecolorallocate($image, 30, 30, 200));
        if ($transparent) {
            imagesavealpha($image, true);
            imagealphablending($image, false);
            imagefilledrectangle($image, 0, 0, $width - 1, intdiv($height, 4), imagecolorallocatealpha($image, 0, 0, 0, 127));
        }
        $stream = fopen('php://memory', 'w+b');
        match ($type) { 'jpeg' => imagejpeg($image, $stream, 90), 'png' => imagepng($image, $stream), 'webp' => imagewebp($image, $stream, 90) };
        rewind($stream);
        return stream_get_contents($stream);
    }

    /** A JPEG as a camera writes it: an EXIF block with its orientation and the place it was taken. */
    private function camera(string $jpeg, int $orientation): string
    {
        $place = self::PLACE."\0";
        $tiff = "II*\0".pack('V', 8).pack('v', 2)
            .pack('vvVvv', 0x0112, 3, 1, $orientation, 0)
            .pack('vvVV', 0x010E, 2, strlen($place), 8 + 2 + 2 * 12 + 4)
            .pack('V', 0).$place;
        $app1 = "Exif\0\0".$tiff;
        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    private function send(array $pupil, string $name, string $contents, array $extra = []): TestResponse
    {
        return $this->post('/api/students/'.$pupil['id'].'/photo', ['photo' => UploadedFile::fake()->createWithContent($name, $contents),
            'version' => $pupil['version'], ...$extra], ['Accept' => 'application/json']);
    }

    /** @return array{0: int, 1: int, 2: string} width, height and bytes of the photo served */
    private function served(array $pupil, string $size = 'full'): array
    {
        $response = $this->get('/api/students/'.$pupil['id'].'/photo?size='.$size)->assertOk()->assertHeader('Content-Type', 'image/jpeg')
            ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Content-Disposition', 'inline');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $bytes = $response->streamedContent();
        [$width, $height] = getimagesizefromstring($bytes);
        return [$width, $height, $bytes];
    }

    /** @return array{0: int, 1: int, 2: int} the colour of a pixel of the served photo */
    private function colour(string $bytes, int $x, int $y): array
    {
        $rgb = imagecolorat(imagecreatefromstring($bytes), $x, $y);
        return [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
    }

    private function files(string $student): array
    {
        return DB::connection('owner')->select("SELECT id, storage_key, original_name, owner_type, archived_at, purged_at FROM school.file_object
            WHERE category = 'student_photo' AND owner_id = ? ORDER BY created_at, original_name", [$student]);
    }

    public function test_a_pupil_needs_no_photo_and_a_photo_is_made_again_upright_reduced_and_without_its_metadata(): void
    {
        $this->manager();
        // AC0: a pupil without a photo, without any error or required field.
        $pupil = $this->pupil();
        $this->assertFalse($pupil['has_photo']);
        $this->get('/api/students/'.$pupil['id'].'/photo')->assertNotFound();
        $sent = $this->camera($this->image('jpeg', 1200, 900), 6);
        $this->assertStringContainsString(self::PLACE, $sent);
        $pupil = $this->send($pupil, 'IMG_2041.JPG', $sent)->assertOk()->assertJsonPath('data.has_photo', true)->assertJsonPath('data.version', 2)
            ->assertJsonMissingPath('data.photo_file_id')->json('data');
        // Turned upright as the camera said (its left side on top), at most 1 024 pixels.
        [$width, $height, $bytes] = $this->served($pupil);
        $this->assertSame([768, 1024], [$width, $height]);
        [$red, , $blue] = $this->colour($bytes, 384, 40);
        $this->assertGreaterThan($blue, $red);
        [$red, , $blue] = $this->colour($bytes, 384, 980);
        $this->assertGreaterThan($red, $blue);
        // AC0: nothing of the sent file's metadata, neither the EXIF block nor the place.
        $this->assertStringNotContainsString('Exif', $bytes);
        $this->assertStringNotContainsString('Alger', $bytes);
        [$width, $height, $thumb] = $this->served($pupil, 'thumb');
        $this->assertSame([128, 128], [$width, $height]);
        $this->assertStringNotContainsString('Alger', $thumb);
        // Two files of the pupil, named by the platform, never by the sender.
        $files = $this->files($pupil['id']);
        $this->assertSame(['photo.jpg', 'vignette.jpg'], array_column($files, 'original_name'));
        $this->assertSame(['student', 'student'], array_column($files, 'owner_type'));
        // The journal says the photo changed, never which image.
        $entry = DB::connection('owner')->selectOne("SELECT changed_fields, before, after FROM school.audit_log
            WHERE object_type = 'student' AND object_id = ? AND action = 'UPDATE' ORDER BY occurred_at DESC LIMIT 1", [$pupil['id']]);
        $this->assertContains('photo_file_id', json_decode($entry->changed_fields, true));
        $this->assertSame('[masqué]', json_decode($entry->after, true)['photo_file_id']);
        $this->assertStringNotContainsString($files[0]->id, $entry->after);
        // The centred square, as the screen proposes it.
        $square = $this->send($pupil, 'carre.png', $this->image('png', 300, 200, true), ['square' => true])->assertOk()->json('data');
        [$width, $height, $bytes] = $this->served($square);
        $this->assertSame([200, 200], [$width, $height]);
        // Transparency becomes white; JPEG either way.
        $this->assertSame([255, 255, 255], $this->colour($bytes, 100, 10));
    }

    public function test_only_jpeg_png_and_webp_images_of_two_megabytes_at_most_are_taken(): void
    {
        $this->manager();
        $pupil = $this->pupil();
        // AC0: a file that is not an image is refused, whatever its name says.
        $this->send($pupil, 'photo.jpg', "%PDF-1.4\n%%EOF\n")->assertUnprocessable()->assertJsonValidationErrors('photo');
        $this->send($pupil, 'photo.png', "nom;prénom\nBenali;Amine\n")->assertUnprocessable()->assertJsonPath('errors.photo.0', __('errors.photo_invalid'));
        $this->send($pupil, 'photo.gif', "GIF89a\x01\x00\x01\x00\x00\x00\x00;")->assertUnprocessable();
        // 2 MB at most as sent.
        $this->send($pupil, 'lourde.jpg', $this->image('jpeg', 40, 40).str_repeat("\0", 2 * 1024 * 1024))
            ->assertUnprocessable()->assertJsonPath('errors.photo.0', __('errors.photo_too_large'));
        // A small file announcing 20 000 × 20 000 pixels is never decoded.
        $header = "\x89PNG\r\n\x1a\n".pack('N', 13).'IHDR'.pack('NNCCCCC', 20000, 20000, 8, 2, 0, 0, 0).pack('N', 0);
        $this->send($pupil, 'immense.png', $header)->assertUnprocessable()->assertJsonPath('errors.photo.0', __('errors.photo_dimensions'));
        $this->assertSame([], $this->files($pupil['id']));
        $this->assertSame([], Storage::disk('files')->allFiles());
        // PNG and WebP become the same JPEG.
        $this->assertTrue((bool) (imagetypes() & IMG_WEBP), 'GD must read WebP: the platform image builds it --with-webp.');
        $pupil = $this->send($pupil, 'photo.webp', $this->image('webp', 500, 400))->assertOk()->json('data');
        $this->assertSame([500, 400], array_slice($this->served($pupil), 0, 2));
        $this->send($pupil, 'photo.png', $this->image('png', 64, 64))->assertOk();
        $this->send($pupil, 'photo.png', $this->image('png', 64, 64))->assertConflict()->assertJsonPath('code', 'stale_version');
    }

    public function test_the_previous_image_is_deleted_when_the_photo_is_replaced_or_removed(): void
    {
        $this->manager();
        $pupil = $this->send($this->pupil(), 'a.jpg', $this->image('jpeg', 300, 300))->assertOk()->json('data');
        $first = $this->files($pupil['id']);
        $pupil = $this->send($pupil, 'b.jpg', $this->image('jpeg', 200, 200))->assertOk()->json('data');
        foreach ($first as $file) {
            Storage::disk('files')->assertMissing($file->storage_key);
            $this->assertNotNull(DB::connection('owner')->table('school.file_object')->where('id', $file->id)->value('purged_at'));
        }
        $this->assertSame([200, 200], array_slice($this->served($pupil), 0, 2));
        $second = array_slice($this->files($pupil['id']), 2);
        // A refused change leaves nothing behind: neither its images nor the photo in place touched.
        $stored = Storage::disk('files')->allFiles();
        $this->send($pupil, 'c.jpg', $this->image('jpeg', 100, 100), ['version' => 1])->assertConflict()->assertJsonPath('code', 'stale_version');
        $this->assertSame($stored, Storage::disk('files')->allFiles());
        $this->deleteJson('/api/students/'.$pupil['id'].'/photo', ['version' => 1])->assertConflict();
        $pupil = $this->deleteJson('/api/students/'.$pupil['id'].'/photo', ['version' => $pupil['version']])->assertOk()
            ->assertJsonPath('data.has_photo', false)->json('data');
        foreach ($second as $file) Storage::disk('files')->assertMissing($file->storage_key);
        $this->assertSame([], Storage::disk('files')->allFiles());
        $this->get('/api/students/'.$pupil['id'].'/photo?size=thumb')->assertNotFound();
        $this->deleteJson('/api/students/'.$pupil['id'].'/photo', ['version' => $pupil['version']])->assertNotFound();
        // A former pupil's record stays as it is.
        $former = $this->postJson('/api/students/'.$pupil['id'].'/status', ['status' => 'former', 'reason' => 'Fin de cycle', 'version' => $pupil['version']])
            ->assertOk()->json('data');
        $this->send($former, 'd.jpg', $this->image('jpeg', 100, 100))->assertConflict()->assertJsonPath('code', 'student_former');
        $this->assertSame([], Storage::disk('files')->allFiles());
    }

    public function test_a_school_that_turns_photos_off_hides_them_without_deleting_them(): void
    {
        $this->manager();
        $pupil = $this->send($this->pupil(), 'a.jpg', $this->image('jpeg', 300, 300))->assertOk()->json('data');
        $kept = $this->served($pupil)[2];
        $this->putJson('/api/settings/student.photo.enabled', ['value' => false])->assertOk();
        $this->getJson('/api/students/'.$pupil['id'])->assertOk()->assertJsonPath('data.has_photo', false);
        $this->get('/api/students/'.$pupil['id'].'/photo')->assertNotFound();
        $this->send($pupil, 'b.jpg', $this->image('jpeg', 100, 100))->assertConflict()->assertJsonPath('code', 'student_photo_disabled');
        $this->putJson('/api/settings/student.photo.enabled', ['value' => true])->assertOk();
        $this->getJson('/api/students?per_page=25')->assertOk()->assertJsonPath('data.0.has_photo', true);
        $this->assertSame($kept, $this->served($pupil)[2]);
    }

    public function test_a_photo_is_seen_and_changed_within_the_pupils_scope_never_as_an_attachment_or_a_public_link(): void
    {
        [$school] = $this->manager();
        $seen = $this->send($this->pupil(['birth_date' => '1990-01-01']), 'a.jpg', $this->image('jpeg', 300, 300))->assertOk()->json('data');
        $hidden = $this->send($this->pupil(['first_name_lat' => 'Lina']), 'b.jpg', $this->image('jpeg', 300, 300))->assertOk()->json('data');
        $photo = DB::connection('owner')->table('school.student')->where('id', $seen['id'])->value('photo_file_id');
        // Not an attachment: no generic route reads it, changes it, or gives a link to it, even to its manager.
        $this->getJson('/api/files/'.$photo)->assertNotFound();
        $this->get('/api/files/'.$photo.'/content')->assertNotFound();
        $this->postJson('/api/files/'.$photo.'/link')->assertNotFound();
        $this->postJson('/api/files/'.$photo.'/archive', ['reason' => 'Essai'])->assertNotFound();
        // A secretary of one group sees and changes the photo of its pupil, not another's.
        $db = DB::connection('owner');
        $year = $db->table('school.academic_year')->insertGetId(['tenant_id' => $school->id, 'code' => 'PHOTO', 'start_date' => now()->startOfYear()->toDateString(),
            'end_date' => now()->endOfYear()->toDateString(), 'status' => 'planned'], 'id');
        $node = $db->selectOne("SELECT id FROM school.ref_node WHERE tenant_id = ? AND type_code = 'grade' LIMIT 1", [$school->id])->id;
        $program = $db->table('school.program')->insertGetId(['tenant_id' => $school->id, 'code' => 'PHOTO', 'year_id' => $year, 'node_id' => $node,
            'labels' => '{"fr":"Programme"}', 'status' => 'active'], 'id');
        $site = $db->selectOne('SELECT id FROM school.site WHERE tenant_id = ? LIMIT 1', [$school->id])->id;
        $group = $db->table('school.study_group')->insertGetId(['tenant_id' => $school->id, 'year_id' => $year, 'code' => 'PHOTO', 'program_id' => $program,
            'site_id' => $site, 'group_type' => 'class', 'labels' => '{"fr":"Classe"}', 'capacity' => 10, 'valid_from' => now()->toDateString(), 'status' => 'active'], 'id');
        $enrollment = $db->table('school.enrollment')->insertGetId(['tenant_id' => $school->id, 'student_id' => $seen['id'], 'program_id' => $program,
            'idempotency_key' => (string) Str::uuid()], 'id');
        $db->table('school.group_membership')->insert(['tenant_id' => $school->id, 'enrollment_id' => $enrollment, 'group_id' => $group]);
        $secretary = $this->account('secretary');
        $this->member($school, $secretary);
        $this->grant($school, $secretary, 'front_office', 'group', [$group]);
        $this->signIn($secretary)->assertOk();
        $this->served($seen, 'thumb');
        $this->get('/api/students/'.$hidden['id'].'/photo?size=thumb')->assertNotFound();
        $this->getJson('/api/files/'.$photo)->assertNotFound();
        $this->send($hidden, 'c.jpg', $this->image('jpeg', 100, 100))->assertNotFound();
        $this->send($seen, 'c.jpg', $this->image('jpeg', 100, 100))->assertOk();
        // Another school sees none of them.
        $other = $this->account('other');
        $this->member($this->school('essential', $other, 'private_school_dz'), $other, 'active', ['academic_hr']);
        $this->signIn($other)->assertOk();
        $this->get('/api/students/'.$seen['id'].'/photo')->assertNotFound();
        $this->send($seen, 'd.jpg', $this->image('jpeg', 100, 100))->assertNotFound();
    }
}
