<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_api_health_returns_json_without_a_session(): void
    {
        $this->get('/api/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok'])
            ->assertHeaderMissing('Set-Cookie');
    }

    public function test_unknown_routes_return_json(): void
    {
        $this->get('/missing')
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_root_has_no_web_page(): void
    {
        $this->get('/')->assertNotFound();
    }
}
