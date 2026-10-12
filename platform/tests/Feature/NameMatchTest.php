<?php

namespace Tests\Feature;

use App\Support\NameMatch;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Names that are probably one name, in the same alphabet or across Arabic and Latin letters (FR-ELV-04, NFR-I18N-03). */
final class NameMatchTest extends TestCase
{
    private static function named(string $last, string $first): array
    {
        $alphabet = preg_match('/\p{Arabic}/u', $last.$first) ? 'ar' : 'lat';
        return ['last_name_'.$alphabet => $last, 'first_name_'.$alphabet => $first];
    }

    public function test_the_key_is_the_one_the_database_searches_with(): void
    {
        foreach (['  HÉLÈNE  Ben-Ali ', 'Œuvre d’Ælis', 'محمّد أمين', 'إبراهيم الطاهر', 'فاطمة الزهراء', 'آمال ٢٠١٢', 'Ça Ñoño', 'Yaël l’Ouarzazi', 'گل ی'] as $text) {
            $this->assertSame((string) DB::connection('owner')->selectOne('SELECT school.search_key(?) AS k', [$text])->k, NameMatch::key($text), $text);
        }
    }

    public function test_an_arabic_name_and_its_latin_spellings_are_one_name(): void
    {
        foreach ([
            // FR-ELV-04 AC1.
            [['Mohamed', 'Amine'], ['محمد', 'أمين']],
            [['Mohammed', 'Amine'], ['مُحَمَّد', 'امين']],
            // A compound first name holds the one in use.
            [['Benali', 'Amine'], ['بن علي', 'محمد أمين']],
            [['Saidi', 'Abdelkader'], ['سعيدي', 'عبد القادر']],
            [['Saidi', 'Abdelkader'], ['سعيدي', 'عبدالقادر']],
            [['Rahmani', 'Abderrahmane'], ['رحماني', 'عبد الرحمن']],
            [['Ait Hocine', 'Youcef'], ['آيت حسين', 'يوسف']],
            [['Bouzid', 'Khadidja'], ['بوزيد', 'خديجة']],
            [['Cherif', 'Aïcha'], ['شريف', 'عائشة']],
            [['Mansouri', 'Djamel'], ['منصوري', 'جمال']],
            [['Belkacem', 'Mustapha'], ['بلقاسم', 'مصطفى']],
            [['Hamdi', 'Fatima Zohra'], ['حمدي', 'فاطمة الزهراء']],
            [['El Hadi', 'Tahar'], ['الهادي', 'الطاهر']],
            [['Kaci', 'Rachid'], ['قاسي', 'رشيد']],
            [['Ouali', 'Zineb'], ['والي', 'زينب']],
        ] as [$latin, $arabic]) {
            $this->assertSame('other_alphabet', NameMatch::compare(self::named(...$latin), self::named(...$arabic)), implode(' ', $latin));
            $this->assertSame('other_alphabet', NameMatch::compare(self::named(...$arabic), self::named(...$latin)), implode(' ', $arabic));
        }
    }

    public function test_other_names_are_not_one_name(): void
    {
        foreach ([
            [['Benali', 'Amine'], ['بن علي', 'ياسين']],
            [['Kaci', 'Nour'], ['قاسي', 'سارة']],
            [['Benali', 'Karim'], ['منصوري', 'كريم']],
            [['Hamdi', 'Lina'], ['حمدي', 'ريم']],
        ] as [$latin, $arabic]) {
            $this->assertNull(NameMatch::compare(self::named(...$latin), self::named(...$arabic)), implode(' ', $latin));
        }
        // In the same alphabet, letters count, vowels included: twins Amine and Amina are two children.
        $this->assertNull(NameMatch::compare(self::named('Benali', 'Amine'), self::named('Benali', 'Amina')));
        $this->assertNull(NameMatch::compare(self::named('Benali', 'Amine'), self::named('Benali', 'Imane')));
        // Written in both alphabets, the same alphabet decides: the vowels Arabic leaves out in sound are written in its letters.
        $amine = ['last_name_lat' => 'Benali', 'first_name_lat' => 'Amine', 'last_name_ar' => 'بن علي', 'first_name_ar' => 'أمين'];
        $imane = ['last_name_lat' => 'Benali', 'first_name_lat' => 'Imane', 'last_name_ar' => 'بن علي', 'first_name_ar' => 'إيمان'];
        $this->assertNull(NameMatch::compare($amine, $imane));
        $this->assertSame('name', NameMatch::compare($amine, ['last_name_lat' => 'BEN ALI', 'first_name_lat' => 'amine']));
    }

    public function test_in_the_same_alphabet_a_name_is_the_same_whatever_its_case_accents_spaces_or_order(): void
    {
        $this->assertSame('name', NameMatch::compare(self::named('Hélène', 'Benali'), self::named('BENALI', 'HELENE')));
        $this->assertSame('name', NameMatch::compare(self::named('Ben Ali', 'Amine'), self::named('Benali', 'Amine')));
        $this->assertSame('name', NameMatch::compare(self::named('محمد', 'أمين'), self::named('مُحَمَّد', 'امين')));
        $this->assertNull(NameMatch::compare(self::named('Mohamed', 'Amine'), self::named('Mohammed', 'Amine')));
        $this->assertNull(NameMatch::compare(self::named('Benali', 'Amine'), ['last_name_lat' => 'Benali']));
    }
}
