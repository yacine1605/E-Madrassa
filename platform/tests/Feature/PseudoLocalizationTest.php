<?php

namespace Tests\Feature;

use App\Mail\AccountLink;
use App\Mail\NotificationMail;
use App\Support\DatabaseErrors;
use App\Support\Locales;
use DOMDocument;
use DOMXPath;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\PseudoTranslations;
use Tests\TestCase;

/**
 * FR-I18-01 AC3 (TST-17): no text written in the code. French is shown as a pseudo-language
 * (every catalog string ⟦marked⟧): the API's messages, the emails and the console's pages are
 * read back, and any text without the marks is a text the catalogs do not hold. The code and
 * the views are read too, the catalogs compared language by language, the screens' styles
 * checked for logical directions only (NFR-I18N-01).
 */
final class PseudoLocalizationTest extends TestCase
{
    /** Around a text, nothing to translate. */
    private const PUNCTUATION = " ()·—-:,.";

    private function pseudo(): void
    {
        PseudoTranslations::install($this->app->make('translator'));
    }

    private static function marked(?string $text): bool
    {
        $text = trim((string) $text);
        return str_starts_with($text, PseudoTranslations::OPEN) && str_ends_with($text, PseudoTranslations::CLOSE);
    }

    /** The answer's message, and every message of its fields, come from the catalogs. */
    private function assertMarked(TestResponse $response): void
    {
        $messages = [$response->json('message'), ...collect($response->json('errors') ?? [])->flatten()->all()];
        foreach ($messages as $message) $this->assertTrue(self::marked($message), 'Text outside the catalogs: '.$message);
    }

    public function test_the_apis_messages_all_come_from_the_catalogs(): void
    {
        $this->pseudo();
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin, 'active', ['tenant_admin']);

        $this->assertMarked($this->postJson('/api/session', [])->assertUnprocessable());
        $this->assertMarked($this->postJson('/api/session', ['login' => 'nobody@example.test', 'password' => 'wrong-password'])->assertUnauthorized());
        $this->assertMarked($this->getJson('/api/sites')->assertUnauthorized());
        $this->assertMarked($this->postJson('/api/recovery/request', ['email' => 'nobody@example.test'])->assertStatus(202));

        $this->signIn($admin)->assertOk();
        $this->assertMarked($this->getJson('/api/nowhere')->assertNotFound());
        $this->assertMarked($this->putJson('/api/account/locale', ['locale' => 'en'])->assertUnprocessable());
        $this->assertMarked($this->putJson('/api/account/password', ['current_password' => 'wrong', 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertUnprocessable());
        // A rule of the database, a refusal of the application, a module the school lacks.
        $this->assertMarked($this->postJson('/api/role-assignments', ['user_id' => $admin['id'], 'role_id' => $this->roleId($school, 'direction'),
            'scope_type' => 'tenant'])->assertStatus(409));
        $this->assertMarked($this->postJson('/api/support/session', ['tenant_id' => $school->id, 'grant_id' => (string) Str::uuid()])->assertForbidden());
        $this->assertMarked($this->postJson('/api/years', [])->assertUnprocessable());
    }

    public function test_the_emails_come_from_the_catalogs(): void
    {
        $this->pseudo();
        foreach ([new AccountLink('activation', 'Amina', 'https://example.test/activation#token=x', 'École A'),
                  new AccountLink('recovery', 'Amina', 'https://example.test/recovery#token=x'),
                  new NotificationMail('Amina', 'Titre', 'École A')] as $mail) {
            $mail->locale('fr');
            $this->assertTrue(self::marked($mail->envelope()->subject), $mail->envelope()->subject);
            foreach (preg_split('/\R/u', $mail->render()) as $line) {
                if (trim($line) === '' || str_starts_with($line, 'https://')) continue;
                $this->assertTrue(self::marked($line), 'Text outside the catalogs: '.$line);
            }
        }
    }

    public function test_the_consoles_pages_come_from_the_catalogs(): void
    {
        $this->pseudo();
        $operator = $this->account('operator');
        DB::connection('owner')->insert('INSERT INTO platform.operator (user_id) VALUES (?)', [$operator['id']]);
        $school = $this->school('essential', null, 'private_school_dz');
        $this->actingAs($this->person($operator));
        $this->withSession(['auth.started_at' => now()->getTimestamp(), 'auth.seen_at' => now()->getTimestamp()]);
        Filament::setCurrentPanel(Filament::getPanel('console'));
        // What the pages may show as it is: the data, and each language's own name.
        $data = [...array_column(DB::connection('owner')->select('SELECT name, slug FROM platform.tenant'), 'name'),
            ...array_column(DB::connection('owner')->select('SELECT slug FROM platform.tenant'), 'slug'),
            ...array_column(DB::connection('owner')->select('SELECT code FROM platform.plan UNION SELECT code FROM platform.profile'), 'code'),
            ...array_column(DB::connection('owner')->select("SELECT labels ->> 'fr' AS l FROM platform.plan UNION SELECT labels ->> 'fr' FROM platform.profile
                UNION SELECT labels ->> 'fr' FROM platform.module"), 'l'),
            ...array_values(Locales::NAMES), 'operator', $operator['email'], 'O'];
        $data = array_map(fn ($text) => trim((string) $text, self::PUNCTUATION), $data);
        foreach (['/console/supervision', '/console/tenants?search='.$school->slug, '/console/tenants/create'] as $page) {
            $unmarked = array_values(array_diff($this->visibleTexts($this->get($page)->assertOk()->getContent()), $data));
            $this->assertSame([], $unmarked, "Text outside the catalogs on $page.");
        }
    }

    /** @return list<string> the page's texts and labelling attributes that hold a letter and no mark */
    private function visibleTexts(string $html): array
    {
        $document = new DOMDocument();
        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR);
        $xpath = new DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//script | //style | //template | //svg | //noscript')) as $hidden) $hidden->parentNode->removeChild($hidden);
        $texts = [];
        foreach ($xpath->query('//text() | //@placeholder | //@title | //@aria-label | //@alt') as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->nodeValue), self::PUNCTUATION);
            if ($text === '' || !preg_match('/\p{L}/u', $text) || str_contains($text, PseudoTranslations::OPEN)) continue;
            $texts[] = $text;
        }
        return array_values(array_unique($texts));
    }

    public function test_school_pages_come_from_the_catalogs_and_arabic_uses_rtl(): void
    {
        $this->pseudo();
        $admin = $this->account('admin');
        $school = $this->school('essential', $admin, 'private_school_dz');
        $this->member($school, $admin, 'active', ['academic_hr']);
        $this->signIn($admin)->assertOk();
        $data = ['Madrassa', $admin['email'], 'admin', 'A', 'MAIN', 'tenant_admin', 'accountant', 'stock_manager', 'teacher', 'student', 'parent',
            'direction', 'academic_hr', 'supervisor', 'front_office', ...array_values(Locales::NAMES), Locales::NAMES['fr'].', '.Locales::NAMES['ar'],
            'École '.$school->slug, $school->slug];
        $data = [...$data, ...array_column(DB::connection('owner')->select("SELECT labels ->> 'fr' AS l FROM school.role WHERE tenant_id = ?
            UNION ALL SELECT labels ->> 'fr' FROM platform.setting_definition UNION ALL SELECT labels ->> 'fr' FROM school.site WHERE tenant_id = ?",
            [$school->id, $school->id]), 'l')];
        foreach (['node_type', 'ref_node', 'subject'] as $table) {
            $rows = DB::connection('owner')->select('SELECT code, labels FROM school.'.$table.' WHERE tenant_id = ?', [$school->id]);
            foreach ($rows as $row) {
                $labels = array_values(json_decode($row->labels, true));
                $data = [...$data, $row->code, ...$labels, ...array_map(fn ($label) => $row->code.' · '.$label, $labels)];
            }
        }
        foreach (['home', 'accounts', 'roles', 'assignments', 'choose-school', 'credentials', 'reference-types', 'reference-nodes', 'subjects', 'years', 'periods',
            'custom-fields', 'settings', 'support-access', 'journal', 'tasks', 'notifications', 'deliveries', 'imports', 'exports', 'sites', 'activation', 'recovery',
            'programs', 'groups', 'students', 'guardians', 'enrollments'] as $page) {
            $data = array_map(fn ($text) => trim((string) $text, self::PUNCTUATION), $data);
            $unmarked = array_values(array_diff($this->visibleTexts($this->get('/school/'.$page)->assertOk()->getContent()), $data));
            $this->assertSame([], $unmarked, "Text outside the catalogs on /school/$page.");
        }
        $this->putJson('/api/account/locale', ['locale' => 'ar'])->assertOk();
        $this->get('/school/accounts')->assertOk()->assertSee('dir="rtl"', false)->assertHeader('Content-Language', 'ar');
    }

    public function test_no_text_is_written_in_the_code(): void
    {
        $found = [];
        // In PHP: a string with letters beyond ASCII (French, Arabic), outside comments, or any
        // string given where a text for people goes. Each language's own name is the exception.
        $forPeople = '/->(label|title|body|helperText|hint|tooltip|placeholder|description|heading|modalHeading|modalDescription|'
            .'modalSubmitActionLabel|brandName|subject)\(\s*[\'"]|\$(navigationLabel|navigationGroup|title|modelLabel|pluralModelLabel|heading)\s*=\s*[\'"]'
            .'|[\'"]message[\'"]\s*=>\s*[\'"]|withMessages\(\[[^\]]*=>\s*[\'"]|\$fail\(\s*[\'"](?!validation\.)|abort\(\d+,\s*[\'"]'
            .'|new (Refusal|RowRefused|DependentOperation)\([^,()]*,\s*[\'"]|new RuntimeException\(\s*[\'"]/';
        foreach ($this->files(['app', 'routes', 'config'], 'php') as $path) {
            $source = file_get_contents($path);
            foreach (token_get_all($source) as $token) {
                if (!is_array($token) || !in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) continue;
                if (preg_match('/\p{L}/u', preg_replace('/[\x00-\x7F]/', '', $token[1])) && !in_array(trim($token[1], '\'"'), Locales::NAMES, true)) {
                    $found[] = "$path:$token[2]: $token[1]";
                }
            }
            foreach (preg_split('/\R/', $source) as $number => $line) {
                if (preg_match($forPeople, $line)) $found[] = $path.':'.($number + 1).': '.trim($line);
            }
        }
        // In the views: no letter outside the echoes, the directives and the tags.
        foreach ($this->files(['resources/views'], 'php') as $path) {
            $text = preg_replace(['/\{\{--.*?--\}\}/s', '/\{\{.*?\}\}/s', '/\{!!.*?!!\}/s', '/<style\b.*?<\/style>/s', '/<script\b.*?<\/script>/s',
                '/@\w+\s*(\((?:[^()]|\((?:[^()]|\([^()]*\))*\))*\))?/', '/<[^>]*>/s', '/\{PAGENO\}|\{nbpg\}/'], ' ', file_get_contents($path));
            if (preg_match('/\p{L}+/u', $text, $match)) $found[] = "$path: $match[0]";
        }
        $this->assertSame([], $found, 'Texts for people belong in the catalogs (lang/fr, lang/ar).');
    }

    public function test_french_and_arabic_say_the_same_things(): void
    {
        $catalogs = fn (string $lang) => collect(glob(base_path("lang/$lang/*.php")))
            ->mapWithKeys(fn ($file) => [basename($file, '.php') => \Illuminate\Support\Arr::dot(require $file)])
            ->put('json', json_decode(file_get_contents(base_path("lang/$lang.json")), true, 4, JSON_THROW_ON_ERROR));
        [$fr, $ar] = [$catalogs('fr'), $catalogs('ar')];
        $this->assertSame($fr->keys()->sort()->values()->all(), $ar->keys()->sort()->values()->all());
        $placeholders = function (string $text) { preg_match_all('/:[a-z_]+/', $text, $m); sort($m[0]); return array_values(array_unique($m[0])); };
        foreach ($fr as $group => $lines) {
            $this->assertSame(array_keys($lines), array_keys($ar[$group]), "Keys of $group");
            foreach ($lines as $key => $line) {
                if ($lines === [] || is_array($line)) continue;
                $this->assertNotSame('', trim((string) $ar[$group][$key]), "$group.$key is empty in Arabic");
                $this->assertSame($placeholders($line), $placeholders($ar[$group][$key]), "Placeholders of $group.$key");
            }
        }
        // Every key the code names, and every code a refusal answers with, has its message.
        $keys = [];
        foreach ($this->files(['app', 'resources/views', 'routes'], 'php') as $path) {
            $source = file_get_contents($path);
            preg_match_all('/(?:__|trans|\$fail)\(\s*\'([a-z_]+\.[a-z0-9_.]*[a-z0-9_])\'/', $source, $m);
            $keys = [...$keys, ...$m[1]];
            preg_match_all('/Refusal::(?:response|of)\(\'([a-z_]+)\'(?![^;]*key:)/', $source, $m);
            $keys = [...$keys, ...array_map(fn ($code) => "errors.$code", $m[1])];
            preg_match_all('/key: \'([a-z_]+)\'/', $source, $m);
            $keys = [...$keys, ...array_map(fn ($key) => "errors.$key", $m[1])];
        }
        $rules = (new ReflectionClass(DatabaseErrors::class))->getConstant('RULES');
        $keys = [...$keys, ...array_map(fn ($rule) => "errors.$rule", $rules),
            ...array_map(fn ($status) => "console.tenant.status.$status", ['active', 'read_only', 'suspended', 'archived']),
            ...array_map(fn ($code) => "errors.$code", ['unauthenticated', 'forbidden', 'not_found', 'method_not_allowed', 'too_large', 'page_expired',
                'too_many_attempts', 'bad_request', 'server_error', 'support_ended', 'mfa_setup_required', 'tenant_suspended', 'membership_inactive'])];
        foreach (array_unique($keys) as $key) {
            [$group, $rest] = explode('.', $key, 2);
            $this->assertTrue(isset($fr[$group][$rest]) || $fr->has($group) && collect($fr[$group])->keys()->contains(fn ($k) => str_starts_with($k, "$rest.")),
                "$key is missing from the French catalog");
            $this->assertTrue(isset($ar[$group][$rest]) || $ar->has($group) && collect($ar[$group])->keys()->contains(fn ($k) => str_starts_with($k, "$rest.")),
                "$key is missing from the Arabic catalog");
        }
    }

    /** Filament's screens in French and Arabic: its catalogs, completed by lang/vendor, hold every key the English one holds. */
    public function test_filaments_catalogs_are_complete_in_french_and_arabic(): void
    {
        $loader = $this->app->make('translator')->getLoader();
        $missing = [];
        foreach ($loader->namespaces() as $namespace => $path) {
            if (!str_starts_with($namespace, 'filament') || !is_dir("$path/en")) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$path/en", RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                $group = substr(str_replace('\\', '/', $file->getPathname()), strlen("$path/en/"), -4);
                $english = array_keys(\Illuminate\Support\Arr::dot($loader->load('en', $group, $namespace)));
                foreach (['fr', 'ar'] as $lang) {
                    $have = \Illuminate\Support\Arr::dot($loader->load($lang, $group, $namespace));
                    foreach (array_diff($english, array_keys($have)) as $key) $missing[] = "$namespace::$group.$key ($lang)";
                }
            }
        }
        $this->assertSame([], $missing);
    }

    /** NFR-I18N-01: start and end, never left and right, so that Arabic mirrors without a second style. PDFs set their direction themselves (mPDF). */
    public function test_the_screens_use_logical_directions_only(): void
    {
        $physical = '/\b(?:m[lr]|p[lr]|-?(?:left|right)|rounded-[lr]|border-[lr]|scroll-[mp][lr])-[\w\[\]]+|\b(?:text|float)-(?:left|right)\b'
            .'|(?:margin|padding|border)-(?:left|right)\s*:|text-align\s*:\s*(?:left|right)|float\s*:\s*(?:left|right)|(?<![\w-])(?:left|right)\s*:/';
        $found = [];
        foreach ($this->files(['resources/views', 'resources/css'], null) as $path) {
            if (str_contains($path, '/views/pdf/') || str_contains($path, '/views/mail/')) continue;
            foreach (preg_split('/\R/', file_get_contents($path)) as $number => $line) {
                if (preg_match($physical, $line, $match)) $found[] = $path.':'.($number + 1).': '.$match[0];
            }
        }
        $this->assertSame([], $found);
    }

    /** @return list<string> */
    private function files(array $directories, ?string $extension): array
    {
        $files = [];
        foreach ($directories as $directory) {
            if (!is_dir(base_path($directory))) continue;
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($directory), RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
                if ($extension === null || $file->getExtension() === $extension) $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);
        return $files;
    }
}
