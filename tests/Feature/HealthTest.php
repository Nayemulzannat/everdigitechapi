<?php

it('reports ok', function () {
    $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
});
