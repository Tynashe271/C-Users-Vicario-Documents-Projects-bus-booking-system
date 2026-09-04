<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The array cache store persists for the life of the test process (unlike
        // RefreshDatabase's per-test DB reset), so a cached trip search — or any future
        // Cache::remember — from one test could otherwise leak into the next. Start clean.
        Cache::flush();
    }
}
