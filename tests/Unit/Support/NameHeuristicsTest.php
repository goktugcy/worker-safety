<?php

declare(strict_types=1);

namespace WorkerSafety\Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WorkerSafety\Support\NameHeuristics;

#[CoversClass(NameHeuristics::class)]
final class NameHeuristicsTest extends TestCase
{
    /**
     * @param list<string> $expected
     */
    #[DataProvider('tokenizations')]
    public function test_tokenize(string $identifier, array $expected): void
    {
        self::assertSame($expected, NameHeuristics::tokenize($identifier));
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function tokenizations(): iterable
    {
        yield 'camel case' => ['currentUser', ['current', 'user']];
        yield 'pascal case' => ['CurrentUser', ['current', 'user']];
        yield 'snake case' => ['current_user', ['current', 'user']];
        yield 'kebab case' => ['current-user', ['current', 'user']];
        yield 'acronym' => ['currentUserID', ['current', 'user', 'id']];
        yield 'digits' => ['user2Factor', ['user', '2', 'factor']];
        yield 'single' => ['user', ['user']];
    }

    #[DataProvider('requestScopedNames')]
    public function test_request_scoped_names_are_recognised(string $identifier): void
    {
        self::assertTrue(
            NameHeuristics::looksRequestScoped($identifier),
            sprintf('%s should read as request scoped', $identifier),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function requestScopedNames(): iterable
    {
        foreach ([
            'user', 'currentUser', 'request', 'response', 'session', 'tenant',
            'account', 'auth', 'token', 'headers', 'locale', 'sessionToken',
            'authenticatedUser', 'requestId', 'User', 'TenantContext',
        ] as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('neutralisedNames')]
    public function test_configuration_and_infrastructure_names_are_neutralised(string $identifier): void
    {
        self::assertFalse(
            NameHeuristics::looksRequestScoped($identifier),
            sprintf('%s should NOT read as request scoped', $identifier),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function neutralisedNames(): iterable
    {
        foreach ([
            'userTable', 'userClass', 'defaultLocale', 'sessionDriver',
            'UserRepository', 'RequestHandler', 'TokenFactory', 'authConfig',
            'userRoleLabels', 'requestFormat', 'AuthServiceProvider',
            'version', 'maxRetries', 'items', 'cache', 'logger',
        ] as $name) {
            yield $name => [$name];
        }
    }

    public function test_matched_tokens_are_returned_once(): void
    {
        self::assertSame(['user'], NameHeuristics::requestTokens('currentUserUser'));
        self::assertSame([], NameHeuristics::requestTokens('userTable'));
    }

    public function test_any_looks_request_scoped(): void
    {
        self::assertTrue(NameHeuristics::anyLooksRequestScoped(['itemCount', 'currentUser']));
        self::assertFalse(NameHeuristics::anyLooksRequestScoped(['itemCount', 'userTable']));
    }

    #[DataProvider('collectionNames')]
    public function test_collection_names(string $identifier, bool $expected): void
    {
        self::assertSame($expected, NameHeuristics::looksLikeCollection($identifier));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function collectionNames(): iterable
    {
        yield 'cache' => ['queryCache', true];
        yield 'registry' => ['handlerRegistry', true];
        yield 'items' => ['items', true];
        yield 'plain' => ['currentUser', false];
    }

    #[DataProvider('resetNames')]
    public function test_reset_method_names(string $method, bool $expected): void
    {
        self::assertSame($expected, NameHeuristics::looksLikeReset($method));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function resetNames(): iterable
    {
        yield 'reset' => ['reset', true];
        yield 'flushState' => ['flushState', true];
        yield 'clearCache' => ['clearCache', true];
        yield 'forget' => ['forget', true];
        yield 'terminate' => ['terminate', true];
        yield 'handle' => ['handle', false];
        yield 'setUser' => ['setUser', false];
    }
}
