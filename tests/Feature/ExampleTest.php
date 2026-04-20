<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

it('returns a successful response', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
