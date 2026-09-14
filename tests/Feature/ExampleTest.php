<?php

test('the root route advertises the MCP endpoint', function () {
    $this->get('/')
        ->assertOk()
        ->assertJsonStructure(['name', 'mcp', 'health']);
});

test('the health endpoint responds', function () {
    $this->get('/up')->assertOk();
});
