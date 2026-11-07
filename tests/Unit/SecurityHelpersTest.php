<?php

namespace Tests\Unit;

use App\Support\OutboundUrlGuard;
use App\Support\SafeText;
use App\Support\Spreadsheet;
use PHPUnit\Framework\TestCase;

class SecurityHelpersTest extends TestCase
{
    protected function tearDown(): void
    {
        OutboundUrlGuard::$resolver = null;
        parent::tearDown();
    }

    public function test_ssrf_guard_blocks_internal_and_reserved_targets(): void
    {
        foreach ([
            'http://127.0.0.1/', 'http://localhost/', 'http://10.1.2.3/', 'http://172.16.0.1/', 'http://192.168.1.1/',
            'http://169.254.169.254/latest/meta-data/', 'http://100.64.0.1/', 'http://0.0.0.0/', 'http://[::1]/',
            'http://[fd00::1]/', 'http://[::ffff:127.0.0.1]/', 'ftp://93.184.216.34/', 'http://user:pw@93.184.216.34/',
            'http://93.184.216.34:22/', 'gopher://93.184.216.34/', 'http://intranet.local/',
        ] as $url) {
            $this->assertFalse(OutboundUrlGuard::check($url)['ok'], "Should block {$url}");
        }
    }

    public function test_ssrf_guard_blocks_public_names_that_resolve_to_private_ips(): void
    {
        OutboundUrlGuard::$resolver = fn () => ['10.0.0.7'];
        $this->assertFalse(OutboundUrlGuard::check('https://rebind.example.com/hook')['ok']);

        OutboundUrlGuard::$resolver = fn () => ['93.184.216.34'];
        $check = OutboundUrlGuard::check('https://hooks.example.com/hook');
        $this->assertTrue($check['ok']);
        $this->assertSame('93.184.216.34', $check['ip']);
        $this->assertFalse(OutboundUrlGuard::httpOptions($check)['allow_redirects']);
    }

    public function test_spreadsheet_cells_are_neutralised(): void
    {
        $this->assertSame("'=1+1", Spreadsheet::cell('=1+1'));
        $this->assertSame("'+91 98", Spreadsheet::cell('+91 98'));
        $this->assertSame("'-2", Spreadsheet::cell('-2'));
        $this->assertSame("'@x", Spreadsheet::cell('@x'));
        $this->assertSame("'\tx", Spreadsheet::cell("\tx"));
        $this->assertSame('plain', Spreadsheet::cell('plain'));
        $this->assertSame('', Spreadsheet::cell(null));
    }

    public function test_message_text_keeps_plain_text_but_drops_markup(): void
    {
        $this->assertSame("don't worry & relax", SafeText::message("  don't worry & relax  "));
        $this->assertSame('if x < 5 and y > 3', SafeText::message('if x < 5 and y > 3'));
        $this->assertSame('Bold', SafeText::message('<script>alert(1)</script><b>Bold</b>'));
        $this->assertSame("line1\nline2", SafeText::message("line1\r\nline2\x00"));
    }

    public function test_profile_fields_cannot_carry_markup(): void
    {
        $this->assertSame('img src=x onerror=alert(1)', SafeText::field('<img src=x onerror=alert(1)>') ?? 'img src=x onerror=alert(1)');
        $this->assertStringNotContainsString('<', (string) SafeText::field('<svg onload=1>Rahul'));
        $this->assertSame('Rahul Sharma', SafeText::field("  Rahul \n Sharma "));
        $this->assertNull(SafeText::url('javascript:alert(1)'));
        $this->assertSame('https://a.test/x', SafeText::url('https://a.test/x'));
    }
}
