<?php

use Illuminate\Support\Facades\Route;

// The Postman collections in docs/ must answer to every API route: admin routes in the admin collection, client routes in
// the client collection. This fails when someone adds a route and forgets its Postman request (or leaves one for a route
// that is gone), so the collections cannot silently go out of date.

const POSTMAN_DOCS = __DIR__.'/../../docs/';

function postmanNormalize(string $path): string
{
    $path = preg_replace('/\{\{[^}]+\}\}/', '{x}', $path);
    $path = preg_replace('/\{[^}]+\}/', '{x}', (string) $path);

    return '/'.trim((string) $path, '/');
}

/** @return array<string, true> "METHOD /path" of every request in a collection, folders flattened */
function postmanRequests(string $file): array
{
    $collection = json_decode((string) file_get_contents(POSTMAN_DOCS.$file), true, flags: JSON_THROW_ON_ERROR);
    $found = [];
    $walk = function (array $items) use (&$walk, &$found) {
        foreach ($items as $item) {
            if (isset($item['item'])) {
                $walk($item['item']);

                continue;
            }
            $raw = is_array($item['request']['url']) ? $item['request']['url']['raw'] : $item['request']['url'];
            $path = explode('?', str_replace('{{baseUrl}}', '', $raw))[0];
            $found[$item['request']['method'].' '.postmanNormalize($path)] = true;
        }
    };
    $walk($collection['item']);

    return $found;
}

/** @return array<string, true> "METHOD /path" of every route that starts with the prefix */
function apiRoutes(string $prefix): array
{
    $routes = [];
    foreach (Route::getRoutes() as $route) {
        $uri = '/'.ltrim($route->uri(), '/');
        if (! str_starts_with($uri, $prefix)) {
            continue;
        }
        foreach ($route->methods() as $method) {
            if (! in_array($method, ['HEAD', 'OPTIONS'], true)) {
                $routes[$method.' '.postmanNormalize($uri)] = true;
            }
        }
    }

    return $routes;
}

it('has a request for every route in the admin collection', function () {
    $missing = array_keys(array_diff_key(apiRoutes('/api/admin'), postmanRequests('everdigitech-admin-api.postman_collection.json')));

    expect($missing)->toBe([], 'Add these to docs/everdigitech-admin-api.postman_collection.json: '.implode(', ', $missing));
});

it('has a request for every route in the client collection', function () {
    $missing = array_keys(array_diff_key(apiRoutes('/api/client'), postmanRequests('everdigitech-client-api.postman_collection.json')));

    expect($missing)->toBe([], 'Add these to docs/everdigitech-client-api.postman_collection.json: '.implode(', ', $missing));
});

it('has no request for a route that does not exist', function (string $file) {
    $known = apiRoutes('/api') + ['GET /sanctum/csrf-cookie' => true];
    $stale = array_keys(array_diff_key(postmanRequests($file), $known));

    expect($stale)->toBe([], "These requests in {$file} have no route: ".implode(', ', $stale));
})->with(['everdigitech-admin-api.postman_collection.json', 'everdigitech-client-api.postman_collection.json']);

it('declares every variable that a request uses', function (string $file) {
    $collection = json_decode((string) file_get_contents(POSTMAN_DOCS.$file), true, flags: JSON_THROW_ON_ERROR);
    $environment = json_decode((string) file_get_contents(POSTMAN_DOCS.'everdigitech-local.postman_environment.json'), true, flags: JSON_THROW_ON_ERROR);
    $declared = array_merge(array_column($collection['variable'] ?? [], 'key'), array_column($environment['values'], 'key'));

    preg_match_all('/\{\{([A-Za-z0-9_]+)\}\}/', (string) json_encode($collection), $matches);
    $undeclared = array_values(array_diff(array_unique($matches[1]), $declared));

    expect($undeclared)->toBe([], 'Declare these variables: '.implode(', ', $undeclared));
})->with(['everdigitech-admin-api.postman_collection.json', 'everdigitech-client-api.postman_collection.json']);

it('keeps both collections valid JSON in the Postman v2.1 format', function (string $file) {
    $collection = json_decode((string) file_get_contents(POSTMAN_DOCS.$file), true, flags: JSON_THROW_ON_ERROR);

    expect($collection['info']['schema'])->toBe('https://schema.getpostman.com/json/collection/v2.1.0/collection.json')
        ->and($collection['item'])->not->toBeEmpty();
})->with(['everdigitech-admin-api.postman_collection.json', 'everdigitech-client-api.postman_collection.json']);
