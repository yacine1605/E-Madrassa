<?php

namespace Tests\Feature;

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

    public function test_the_policy_can_report_without_blocking(): void
    {
        config(['platform.csp_report_only' => true]);
        $response = $this->get('/school/login')->assertOk();
        $this->assertFalse($response->headers->has('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self' 'nonce-", (string) $response->headers->get('Content-Security-Policy-Report-Only'));
    }
}
