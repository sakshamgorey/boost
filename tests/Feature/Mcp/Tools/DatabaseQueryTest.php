<?php

declare(strict_types=1);

use Laravel\Boost\Mcp\Tools\DatabaseQuery;
use Laravel\Mcp\Request;
use Illuminate\Support\Facades\DB;

test('it executes a read-only query', function (): void {
    DB::shouldReceive('connection')->with(null)->andReturnSelf();
    DB::shouldReceive('select')->with('SELECT * FROM users')->andReturn([['id' => 1]]);

    $tool = new DatabaseQuery;
    $response = $tool->handle(new Request(['query' => 'SELECT * FROM users']));

    expect($response)->isToolResult()
        ->toolHasNoError()
        ->toolJsonContentToMatchArray([['id' => 1]]);
});

test('it handles leading comments and whitespace', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);

    $tool = new DatabaseQuery;

    $queries = [
        "-- comment\nSELECT * FROM users",
        "/* multi\nline */ SELECT * FROM users",
        "  \n  SELECT * FROM users",
        "# hash comment\nSELECT * FROM users",
    ];

    foreach ($queries as $query) {
        $response = $tool->handle(new Request(['query' => $query]));
        expect($response)->toolHasNoError();
    }
});

test('it rejects write queries', function (): void {
    $tool = new DatabaseQuery;
    $response = $tool->handle(new Request(['query' => 'INSERT INTO users (name) VALUES ("John")']));

    expect($response)->toolHasError()
        ->toolTextContains('Only read-only queries are allowed');
});

test('it handles connection name', function (): void {
    DB::shouldReceive('connection')->with('other')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);

    $tool = new DatabaseQuery;
    $response = $tool->handle(new Request(['query' => 'SELECT 1', 'database' => 'other']));
    expect($response)->toolHasNoError();
});

test('it validates WITH SELECT queries', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request(['query' => 'WITH t AS (SELECT 1) SELECT * FROM t']));
    expect($response)->toolHasNoError();

    $response = $tool->handle(new Request(['query' => 'WITH t AS (SELECT 1) UPDATE users SET name = "foo"']));
    expect($response)->toolHasError();
});
