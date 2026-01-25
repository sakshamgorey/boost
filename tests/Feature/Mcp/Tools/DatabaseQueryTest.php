<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Laravel\Boost\Mcp\Tools\DatabaseQuery;
use Laravel\Mcp\Request;

it('executes allowed read-only queries', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);

    $tool = new DatabaseQuery;

    $queries = [
        'SELECT * FROM users',
        'SHOW TABLES',
        'EXPLAIN SELECT * FROM users',
        'DESCRIBE users',
        'DESC users',
        'VALUES (1, 2, 3)',
        'TABLE users',
        'WITH cte AS (SELECT * FROM users) SELECT * FROM cte',
    ];

    foreach ($queries as $query) {
        $response = $tool->handle(new Request(['query' => $query]));
        expect($response)->isToolResult()
            ->toolHasNoError();
    }
});

it('blocks destructive queries', function (): void {
    DB::shouldReceive('select')->never();

    $tool = new DatabaseQuery;

    $queries = [
        'DELETE FROM users',
        'UPDATE users SET name = "x"',
        'INSERT INTO users VALUES (1)',
        'DROP TABLE users',
    ];

    foreach ($queries as $query) {
        $response = $tool->handle(new Request(['query' => $query]));
        expect($response)->isToolResult()
            ->toolHasError()
            ->toolTextContains('Only read-only queries are allowed');
    }
});

it('blocks extended destructive keywords for mysql postgres and sqlite', function (): void {
    DB::shouldReceive('select')->never();

    $tool = new DatabaseQuery;

    $queries = [
        'REPLACE INTO users VALUES (1)',
        'TRUNCATE TABLE users',
        'ALTER TABLE users ADD COLUMN age INT',
        'CREATE TABLE hackers (id INT)',
        'RENAME TABLE users TO old_users',
    ];

    foreach ($queries as $query) {
        $response = $tool->handle(new Request(['query' => $query]));
        expect($response)->isToolResult()
            ->toolHasError()
            ->toolTextContains('Only read-only queries are allowed');
    }
});

it('handles empty queries gracefully', function (): void {
    $tool = new DatabaseQuery;

    foreach (['', '   ', "\n\t"] as $query) {
        $response = $tool->handle(new Request(['query' => $query]));
        expect($response)->isToolResult()
            ->toolHasError()
            ->toolTextContains('Please pass a valid query');
    }
});

it('allows queries starting with any allowed keyword even when identifiers look like SQL keywords', function (): void {
    DB::shouldReceive('connection')->andReturnSelf();
    DB::shouldReceive('select')->andReturn([]);

    $tool = new DatabaseQuery;

    $queries = [
        'SELECT * FROM delete',
        'SHOW TABLES LIKE "drop"',
        'EXPLAIN SELECT * FROM update',
        'DESCRIBE delete_log',
        'DESC update_history',
        'WITH delete_cte AS (SELECT 1) SELECT * FROM delete_cte',
        'VALUES (1), (2)',
        'TABLE update',
    ];

    foreach ($queries as $query) {
        $response = $tool->handle(new Request([
            'query' => $query,
        ]));

        expect($response)->isToolResult()
            ->toolHasNoError();
    }
});

it('adds prefix to tables when tables parameter is provided', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // Verify the query has the prefix
        expect($query)->toContain('arpg_users');
        expect($query)->not->toContain(' FROM users');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM users',
        'tables' => ['users'],
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('handles multiple tables with prefix', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        expect($query)->toContain('arpg_users');
        expect($query)->toContain('arpg_posts');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM users JOIN posts ON users.id = posts.user_id',
        'tables' => ['users', 'posts'],
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('handles quoted table names with prefix', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        expect($query)->toContain('`arpg_users`');
        expect($query)->toContain('"arpg_posts"');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM `users` JOIN "posts" ON users.id = posts.user_id',
        'tables' => ['users', 'posts'],
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('does not add prefix when tables parameter is not provided', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // Verify the query does NOT have the prefix
        expect($query)->toContain('users');
        expect($query)->not->toContain('arpg_users');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request(['query' => 'SELECT * FROM users']));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('only prefixes exact table name matches and not substrings', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // Verify 'users' is prefixed correctly
        expect($query)->toContain('arpg_users');
        // Verify 'users_post' is prefixed correctly (it's in the tables array)
        expect($query)->toContain('arpg_users_post');
        // Verify 'comments_users' is prefixed correctly (it's in the tables array)
        expect($query)->toContain('arpg_comments_users');
        // Verify word boundaries work - 'users' inside 'users_post' doesn't create double prefix
        expect($query)->not->toContain('arpg_arpg_users_post');
        return [];
    });

    $tool = new DatabaseQuery;

    // All tables are in the array, so all should be prefixed
    // Word boundaries ensure 'users' inside 'users_post' doesn't get double-prefixed
    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM users JOIN users_post ON users.id = users_post.user_id JOIN comments_users ON users.id = comments_users.user_id',
        'tables' => ['users', 'users_post', 'comments_users'],
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('prefixes table-qualified column references correctly', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // users.id should become arpg_users.id
        expect($query)->toContain('arpg_users.id');
        expect($query)->toContain('arpg_posts.user_id');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT users.id, posts.title FROM users JOIN posts ON users.id = posts.user_id',
        'tables' => ['users', 'posts'],
    ]));
    expect($response)->isToolResult()->toolHasNoError();
});

it('does not prefix table names inside string literals', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        expect($query)->toContain('FROM arpg_status');
        expect($query)->toContain("= 'status'");  // string should be unchanged!
        expect($query)->not->toContain("'arpg_status'");
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => "SELECT * FROM status WHERE type = 'status'",
        'tables' => ['status'],
    ]));
    expect($response)->isToolResult()->toolHasNoError();
});

it('prefixes table names but not aliases', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        expect($query)->toContain('FROM arpg_users AS u');
        expect($query)->toContain('u.id');  // alias should NOT be prefixed
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT u.id, u.name FROM users AS u WHERE u.active = 1',
        'tables' => ['users'],
    ]));
    expect($response)->isToolResult()->toolHasNoError();
});

it('does not add prefix when tables array is empty', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // Verify the query does NOT have the prefix
        expect($query)->toContain('users');
        expect($query)->not->toContain('arpg_users');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM users',
        'tables' => [],
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('skips prefixing when table name already starts with prefix', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // Should not double-prefix
        expect($query)->toContain('arpg_users');
        expect($query)->not->toContain('arpg_arpg_users');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM arpg_users',
        'tables' => ['arpg_users'], // Already prefixed
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('handles schema-qualified table names with prefix', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'app_');
    config()->set('database.connections.mysql.driver', 'mysql');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // Schema-qualified names should be prefixed correctly
        // Word boundaries allow matching 'users' in 'public.users'
        expect($query)->toContain('app_users');
        expect($query)->toContain('app_posts');
        // Schema prefix should remain
        expect($query)->toContain('public.');
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM public.users JOIN posts ON users.id = posts.user_id',
        'tables' => ['users', 'posts'],
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('handles complex escape sequences in string literals', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturnUsing(function ($query) {
        // Table should be prefixed
        expect($query)->toContain('FROM arpg_status');
        // String literal with escaped quotes should remain unchanged
        expect($query)->toContain("= 'O\\'Reilly'");
        expect($query)->toContain("= 'status'");
        // Should not prefix table name inside string
        expect($query)->not->toContain("'arpg_status'");
        return [];
    });

    $tool = new DatabaseQuery;

    $response = $tool->handle(new Request([
        'query' => "SELECT * FROM status WHERE name = 'O\\'Reilly' AND type = 'status'",
        'tables' => ['status'],
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});

it('allows valid table names with dots for schema qualification', function (): void {
    config()->set('database.default', 'mysql');
    config()->set('database.connections.mysql.prefix', 'arpg_');

    $mockConnection = \Mockery::mock();
    DB::shouldReceive('connection')->with('mysql')->andReturn($mockConnection);
    $mockConnection->shouldReceive('select')->andReturn([]);

    $tool = new DatabaseQuery;

    // This should not error - dots are allowed for schema.table format
    $response = $tool->handle(new Request([
        'query' => 'SELECT * FROM public.users',
        'tables' => ['public.users'], // This is valid for schema qualification
    ]));
    expect($response)->isToolResult()
        ->toolHasNoError();
});
