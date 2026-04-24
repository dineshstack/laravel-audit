<?php
// tests/DiffServiceTest.php

namespace Dineshstack\LaravelAudit\Tests;

use PHPUnit\Framework\TestCase;
use Dineshstack\LaravelAudit\Services\DiffService;
use Dineshstack\LaravelAudit\Services\MaskingService;

class DiffServiceTest extends TestCase
{
    private DiffService $diff;

    protected function setUp(): void
    {
        $this->diff = new DiffService();
    }

    public function test_detects_changed_fields(): void
    {
        $old = ['name' => 'John', 'status' => 'pending', 'email' => 'john@example.com'];
        $new = ['name' => 'Jane', 'status' => 'active',  'email' => 'john@example.com'];

        $result = $this->diff->compute($old, $new);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('status', $result);
        $this->assertArrayNotHasKey('email', $result);

        $this->assertEquals('John', $result['name']['old']);
        $this->assertEquals('Jane', $result['name']['new']);
        $this->assertEquals('changed', $result['name']['type']);
    }

    public function test_detects_added_fields(): void
    {
        $old    = ['name' => 'John'];
        $new    = ['name' => 'John', 'phone' => '+1234567890'];
        $result = $this->diff->compute($old, $new);

        $this->assertEquals('added', $result['phone']['type']);
        $this->assertNull($result['phone']['old']);
    }

    public function test_detects_removed_fields(): void
    {
        $old    = ['name' => 'John', 'phone' => '+1234567890'];
        $new    = ['name' => 'John'];
        $result = $this->diff->compute($old, $new);

        $this->assertEquals('removed', $result['phone']['type']);
        $this->assertNull($result['phone']['new']);
    }

    public function test_returns_empty_for_identical_arrays(): void
    {
        $attrs  = ['name' => 'John', 'status' => 'active'];
        $result = $this->diff->compute($attrs, $attrs);
        $this->assertEmpty($result);
    }

    public function test_summarise_lists_changed_fields(): void
    {
        $diff = ['name' => [], 'status' => [], 'email' => []];
        $this->assertStringContainsString('name', $this->diff->summarise($diff));
        $this->assertStringContainsString('status', $this->diff->summarise($diff));
    }
}

class MaskingServiceTest extends TestCase
{
    public function test_redacts_password_field(): void
    {
        $svc    = new MaskingService();
        $result = $svc->mask(['name' => 'John', 'password' => 'secret123', 'email' => 'j@example.com']);

        $this->assertEquals('[REDACTED]', $result['password']);
        $this->assertEquals('John',       $result['name']);
        $this->assertEquals('j@example.com', $result['email']);
    }

    public function test_redacts_nested_token(): void
    {
        $svc    = new MaskingService();
        $result = $svc->mask(['user' => ['api_token' => 'abc123', 'name' => 'Jane']]);

        $this->assertEquals('[REDACTED]', $result['user']['api_token']);
        $this->assertEquals('Jane',       $result['user']['name']);
    }

    public function test_case_insensitive_match(): void
    {
        $svc    = new MaskingService();
        $result = $svc->mask(['Password' => 'secret', 'API_KEY' => 'key123']);

        $this->assertEquals('[REDACTED]', $result['Password']);
        $this->assertEquals('[REDACTED]', $result['API_KEY']);
    }
}
