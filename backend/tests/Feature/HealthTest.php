<?php

it('reports health without authentication and sets X-Served-By', function () {
    $this->getJson('/api/health')
        ->assertOk()
        ->assertHeader('X-Served-By', '127.0.0.1:8001')
        ->assertJsonPath('status', 'ok')
        ->assertJsonPath('db', true)
        ->assertJsonStructure(['status', 'hostname', 'instance', 'db', 'redis', 'time']);
});

it('adds X-Served-By to every API response, including errors', function () {
    $this->getJson('/api/does-not-exist')
        ->assertNotFound()
        ->assertHeader('X-Served-By');
});
