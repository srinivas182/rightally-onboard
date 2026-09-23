<?php

namespace Tests\Unit;

use App\Support\UsPhone;
use PHPUnit\Framework\TestCase;

class UsPhoneTest extends TestCase
{
    public function test_accepts_common_formats(): void
    {
        foreach (['(305) 555-0148', '305.555.0148', '+1 305 555 0148', '13055550148'] as $input) {
            $this->assertSame('+13055550148', UsPhone::toE164($input), $input);
        }
    }

    public function test_rejects_invalid_numbers(): void
    {
        foreach (['123', '055 555 0148', '305 155 0148', ''] as $input) {
            $this->assertNull(UsPhone::toE164($input), $input);
        }
    }

    public function test_formats_for_display(): void
    {
        $this->assertSame('(305) 555-0148', UsPhone::format('+13055550148'));
    }
}
