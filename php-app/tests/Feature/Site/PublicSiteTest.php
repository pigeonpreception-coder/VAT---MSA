<?php

namespace Tests\Feature\Site;

use App\Models\User;
use App\Support\Site\VerificationCode;
use Database\Seeders\LicensePlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The public marketing site (App\Http\Controllers\Site\SiteController):
 * the pages a visitor sees before signing in or applying for access.
 */
class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'vfy_0123456789abcdef0123456789abcdef';

    /** @return list<string> */
    private function publicPaths(): array
    {
        return ['/', '/product', '/trust', '/plans', '/verify'];
    }

    private function user(): User
    {
        return User::create([
            'id' => (string) Str::uuid(), 'name' => 'Site Tester', 'email' => 'tester@site.test',
            'password' => bcrypt('password'), 'role' => 'TAXPAYER_OWNER', 'taxpayer_id' => null, 'status' => 'ACTIVE',
        ]);
    }

    public function test_a_guest_lands_on_the_marketing_home_page_with_both_ways_in(): void
    {
        $response = $this->get('/');

        $response->assertOk();
        $response->assertSee('certified', false);
        $response->assertSee(route('signup.create'), false);
        $response->assertSee(route('login'), false);
        $response->assertSee('Illustrative sample', false);
    }

    public function test_a_signed_in_user_is_sent_from_the_root_to_their_dashboard(): void
    {
        $this->actingAs($this->user())->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_every_public_page_renders_for_a_guest(): void
    {
        foreach ($this->publicPaths() as $path) {
            $this->get($path)->assertOk();
        }
    }

    public function test_the_public_pages_do_not_shadow_the_consoles_authenticated_routes(): void
    {
        // /security, /platform and /developer are console screens; the public
        // security page lives at /trust precisely so these stay untouched.
        foreach (['/security', '/platform', '/developer', '/documents', '/reports'] as $path) {
            $this->get($path)->assertRedirect('/login');
        }
    }

    public function test_no_public_page_loads_anything_from_a_third_party_host(): void
    {
        // The app's CSP is default-src 'self'; the site is built to comply.
        foreach ($this->publicPaths() as $path) {
            $html = $this->get($path)->getContent();
            $this->assertDoesNotMatchRegularExpression('~(?:src|href)=["\']https?://(?!vat\.safi-nuru\.com|localhost|127\.0\.0\.1)~i', $html, "$path references an external resource");
            $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        }
    }

    public function test_the_plans_page_shows_the_open_plan_with_its_limits_from_the_database(): void
    {
        $this->seed(LicensePlanSeeder::class);

        $response = $this->get('/plans');

        $response->assertOk();
        $response->assertSee('Professional Pilot');
        $response->assertSee('active users');
        $response->assertSee('100,000');
        $response->assertSee('Scoped API clients to connect your ERP or POS.');
        // The seeded database description mentions webhooks, whose delivery
        // is not live; the public copy must not.
        $response->assertDontSee('webhooks');
    }

    public function test_the_home_page_lists_the_same_plan_and_orders_capabilities_sensibly(): void
    {
        $this->seed(LicensePlanSeeder::class);

        $this->get('/')->assertOk()->assertSeeInOrder(['Core VAT management', 'Organisation administration', 'Advanced workflow']);
    }

    public function test_the_plans_page_says_so_plainly_when_no_plan_is_open(): void
    {
        $this->get('/plans')->assertOk()->assertSee('Applications are not open right now.');
    }

    public function test_the_verify_form_redirects_a_bare_code_to_its_certificate_page(): void
    {
        $this->get('/verify?code='.self::TOKEN)->assertRedirect(route('verify.show', ['token' => self::TOKEN]));
    }

    public function test_the_verify_form_extracts_the_code_from_a_pasted_link(): void
    {
        $expected = route('verify.show', ['token' => self::TOKEN]);

        $this->get('/verify?code='.urlencode('https://vat.safi-nuru.com/verify/'.self::TOKEN))->assertRedirect($expected);
        $this->get('/verify?code='.urlencode('  https://vat.safi-nuru.com/api/v1/verify/'.self::TOKEN.'?utm=qr  '))->assertRedirect($expected);
    }

    public function test_the_verify_form_never_redirects_anywhere_but_the_certificate_page(): void
    {
        foreach (['//evil.example/x', 'https://evil.example/', '../../etc/passwd', 'javascript:alert(1)', 'abc'] as $input) {
            $response = $this->get('/verify?code='.urlencode($input));
            $response->assertOk();
            $response->assertSee('does not look like a verification code');
        }
    }

    public function test_an_empty_verify_form_just_renders(): void
    {
        $this->get('/verify')->assertOk()->assertDontSee('does not look like a verification code');
    }

    public function test_the_sitemap_is_xml_that_lists_the_public_pages(): void
    {
        $response = $this->get('/sitemap.xml');

        $response->assertOk();
        $this->assertStringStartsWith('<?xml version="1.0" encoding="UTF-8"?>', $response->getContent());
        $this->assertStringContainsString('application/xml', (string) $response->headers->get('Content-Type'));
        $this->assertNotFalse(simplexml_load_string($response->getContent()), 'sitemap must be well-formed XML');
        foreach (['site.home', 'site.product', 'site.trust', 'site.plans', 'site.verify', 'signup.create'] as $name) {
            $response->assertSee(route($name), false);
        }
    }

    public function test_optional_contact_details_appear_only_when_configured(): void
    {
        $this->get('/trust')->assertOk()->assertDontSee('mailto:');

        config(['site.contact_email' => 'security@example.test', 'site.operator' => 'Example Advisory CC']);

        $this->get('/trust')->assertOk()->assertSee('mailto:security@example.test', false)->assertSee('Example Advisory CC');
    }

    public function test_the_site_ships_real_icon_files_for_browsers_that_ignore_svg_favicons(): void
    {
        // favicon.ico used to be an empty placeholder; Safari and crawlers
        // request it directly rather than reading the <link> tags.
        $this->assertSame("\x00\x00\x01\x00", substr((string) file_get_contents(public_path('favicon.ico')), 0, 4), 'favicon.ico must be a real ICO file');
        $this->assertStringStartsWith("\x89PNG", (string) file_get_contents(public_path('site/apple-touch-icon.png')));

        $this->get('/')->assertSee('/favicon.ico', false)->assertSee('/site/apple-touch-icon.png', false);
    }

    public function test_the_trust_page_states_what_the_pilot_does_not_yet_do(): void
    {
        $this->get('/trust')->assertOk()
            ->assertSee('Certificates are pilot certificates.')
            ->assertSee('Outbound webhook delivery is not live.');
    }

    public function test_verification_code_extraction_accepts_only_token_characters(): void
    {
        $this->assertSame(self::TOKEN, VerificationCode::extract(self::TOKEN));
        $this->assertSame(self::TOKEN, VerificationCode::extract("https://x.test/verify/".self::TOKEN."#top"));
        $this->assertSame(self::TOKEN, VerificationCode::extract("vfy_01234567\n89abcdef0123456789abcdef"));
        $this->assertNull(VerificationCode::extract(''));
        $this->assertNull(VerificationCode::extract('short'));
        $this->assertNull(VerificationCode::extract('has spaces/and?query=1'));
        $this->assertNull(VerificationCode::extract(str_repeat('a', 200)));
    }
}
