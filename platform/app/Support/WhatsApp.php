<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

/**
 * FR-PRE-01: a message sent through the WhatsApp Business Platform (Meta's Cloud API), as the
 * school's template approved by Meta: a business may write first only with such a template. The
 * account is the school's (DEPLOY.md): its access token, its phone number's identifier, the
 * template's name, in French and Arabic.
 */
final class WhatsApp
{
    public function configured(): bool
    {
        return filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * The template sent to a number in international form, its body's parameters in order; Meta's
     * identifier of the message. The account is set up (`configured`).
     */
    public function send(string $to, string $locale, array $parameters): string
    {
        $response = Http::withToken((string) config('services.whatsapp.token'))->timeout(15)->acceptJson()
            ->post('https://graph.facebook.com/'.config('services.whatsapp.version').'/'.config('services.whatsapp.phone_number_id').'/messages', [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'template',
                'template' => [
                    'name' => config('services.whatsapp.template'),
                    'language' => ['code' => $locale],
                    'components' => [['type' => 'body', 'parameters' => array_map(fn ($value) => ['type' => 'text', 'text' => (string) $value], $parameters)]],
                ],
            ])->throw();
        return (string) $response->json('messages.0.id');
    }

    /**
     * A phone as WhatsApp takes it: its digits in international form, without the `+`; an Algerian
     * number written nationally (0550 12 34 56) gets its country code. Null when it cannot be one.
     */
    public static function number(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if (str_starts_with((string) $phone, '+')) $number = $digits;
        elseif (str_starts_with($digits, '00')) $number = substr($digits, 2);
        elseif (strlen($digits) === 10 && $digits[0] === '0') $number = '213'.substr($digits, 1);
        elseif (strlen($digits) === 9 && in_array($digits[0], ['5', '6', '7'], true)) $number = '213'.$digits;
        else $number = $digits;
        return preg_match('/^[1-9]\d{7,14}$/', $number) ? $number : null;
    }
}
