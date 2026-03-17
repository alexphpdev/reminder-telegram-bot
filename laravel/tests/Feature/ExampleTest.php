<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_exposes_the_health_check_endpoint(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }
}
