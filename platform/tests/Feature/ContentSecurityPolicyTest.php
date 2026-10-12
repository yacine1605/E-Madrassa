<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\TestCase;

/** NFR-SEC-03 (SECURITY-REVIEW.md, F4): every page sends the policy, and its own scripts are allowed by it. */
final class ContentSecurityPolicyTest extends TestCase
{
    public function test_each_page_carries_a_fresh_nonce_and_every_inline_script_bears_it(): void
    {
        $nonces = [];
        foreach (['/school/login', '/school/activation', '/console/login'] as $path) {
            $response = $this->get($path)->assertOk();
            $policy = (string) $response->headers->get('Content-Security-Policy');
            $this->assertMatchesRegularExpression("/script-src 'self' 'nonce-[A-Za-z0-9]{40}'/", $policy, $path);
            $this->assertStringContainsString("object-src 'none'", $policy);
            $this->assertStringContainsString("frame-ancestors 'self'", $policy);
            preg_match("/'nonce-([A-Za-z0-9]{40})'/", $policy, $nonce);
            $nonces[] = $nonce[1];
            preg_match_all('/<script\b([^>]*)>/i', $response->getContent(), $scripts);
            $this->assertNotEmpty($scripts[1], $path);
            foreach ($scripts[1] as $attributes) {
                // A script of the platform's own files, or inline with this response's nonce: nothing else would run.
                if (preg_match('/\bsrc="([^"]+)"/', $attributes, $src)) {
                    $this->assertMatchesRegularExpression('#^(/|'.preg_quote(config('app.url'), '#').'/)#', html_entity_decode($src[1]), $path);
                } else {
                    $this->assertStringContainsString('nonce="'.$nonce[1].'"', $attributes, "$path: an inline script without the nonce");
                }
            }
        }
        $this->assertCount(3, array_unique($nonces));
    }

    public function test_a_signed_in_page_loads_nothing_from_elsewhere_its_avatar_included(): void
    {
        $school = $this->school();
        $account = $this->account('nadia');
        $this->member($school, $account);
        $this->signIn($account)->assertOk();
        $page = $this->get('/school/home')->assertOk()->getContent();
        // The initials are drawn here: no outside service learns who is signed in.
        $this->assertStringNotContainsString('ui-avatars.com', $page);
        $this->assertStringContainsString('data:image/svg+xml;base64,', $page);
        preg_match_all('/<(?:img|script|link|iframe)\b[^>]*\b(?:src|href)="(https?:[^"]+)"/i', $page, $external);
        foreach ($external[1] as $url) $this->assertStringStartsWith(config('app.url'), html_entity_decode($url));
    }

    /** F4: 'unsafe-eval' stays, since Filament 5.10 cannot run Alpine's CSP build; the trial serves that build without it. */
    public function test_the_strict_trial_serves_alpines_csp_build_without_unsafe_eval(): void
    {
        // First: Livewire writes its script once in the application's life.
        config(['platform.csp_strict' => true, 'livewire.csp_safe' => true]);
        $response = $this->get('/school/login')->assertOk();
        $this->assertStringNotContainsString("'unsafe-eval'", (string) $response->headers->get('Content-Security-Policy'));
        // Livewire's script, served by its route: the build that parses expressions instead of evaluating them.
        $this->assertMatchesRegularExpression('#<script src="([^"]*/livewire[^"]*\.js[^"]*)"#', $response->getContent());
        preg_match('#<script src="([^"]*/livewire[^"]*\.js[^"]*)"#', $response->getContent(), $script);
        $served = $this->get(html_entity_decode($script[1]))->assertOk()->baseResponse;
        $this->assertStringContainsString('CSP Parser Error', $served instanceof BinaryFileResponse ? $served->getFile()->getContent() : $served->getContent());
        config(['platform.csp_strict' => false, 'livewire.csp_safe' => false]);
        $this->assertStringContainsString("'unsafe-eval'", (string) $this->get('/school/login')->headers->get('Content-Security-Policy'));
    }

    /**
     * F4: what 'unsafe-eval' lets run is an Alpine attribute, and none can come from data. The
     * platform writes no raw HTML, and Filament's sanitizer, which renders the notifications,
     * drops every attribute Alpine or Livewire would read.
     */
    public function test_no_markup_from_data_carries_an_alpine_attribute(): void
    {
        foreach (Finder::create()->in([resource_path('views'), app_path()])->files()->name('*.php') as $file) {
            $this->assertDoesNotMatchRegularExpression('/\{!!|\bx-html\b|HtmlString|->html\(|->markdown\(|->allowHtml\(/', $file->getContents(),
                $file->getRelativePathname().' writes raw HTML');
        }
        $clean = Str::sanitizeHtml('<p class="note" x-data="{}" x-init="alert(1)" x-on:click="go()" @click="go()" :class="c" wire:click="go">Bonjour</p>');
        $this->assertStringContainsString('Bonjour', $clean);
        $this->assertDoesNotMatchRegularExpression('/x-|@click|:class|wire:/', $clean);
    }

    public function test_the_policy_can_report_without_blocking(): void
    {
        config(['platform.csp_report_only' => true]);
        $response = $this->get('/school/login')->assertOk();
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self' 'nonce-", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }
}
