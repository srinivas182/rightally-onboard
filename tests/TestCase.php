<?php

namespace Tests;

use App\Services\Settings\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Views are rendered without a built Vite manifest in CI.
        $this->withoutVite();

        // Most tests exercise the open flow; the invitation-only rule has its own tests.
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            app(SettingsService::class)->setMany('pricing', ['require_coupon' => '0']);
        }
    }
}
