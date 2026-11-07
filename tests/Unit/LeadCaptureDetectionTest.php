<?php

namespace Tests\Unit;

use App\Services\LeadCaptureService;
use PHPUnit\Framework\TestCase;

class LeadCaptureDetectionTest extends TestCase
{
    public function test_interested_service_detection(): void
    {
        $cases = [
            'I want to buy VPS hosting for my store' => 'vps',
            'Need cPanel shared hosting for 3 WordPress websites' => 'hosting',
            'I need hosting for my domain example.com' => 'hosting',
            'Can I transfer my domain anitadesigns.com to you?' => 'domain',
            'Mujhe .in domain register karna hai' => 'domain',
            'SSL certificate for my domain' => 'ssl',
            'business email for 10 users' => 'email',
            'my wordpress site is slow' => 'wordpress',
            'hello' => null,
        ];
        foreach ($cases as $text => $expected) {
            $this->assertSame($expected, LeadCaptureService::detectService($text), $text);
        }
    }

    public function test_customer_website_detection_ignores_email_domains(): void
    {
        $this->assertSame('rahulstore.in', LeadCaptureService::detectWebsite('VPS for my store rahulstore.in please'));
        $this->assertSame('https://shop.example.com/path', LeadCaptureService::detectWebsite('see https://shop.example.com/path.'));
        $this->assertNull(LeadCaptureService::detectWebsite('my mail is someone@mail.test'));
        $this->assertNull(LeadCaptureService::detectWebsite('contact me at a.b@gmail.com'));
        $this->assertNull(LeadCaptureService::detectWebsite(null));
    }
}
