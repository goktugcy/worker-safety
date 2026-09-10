<?php

declare(strict_types=1);

namespace WorkerSafety\Support;

/**
 * Identifier heuristics shared by the naming sensitive rules.
 *
 * The goal is a deliberately conservative signal: a name only counts as
 * request scoped when it contains request vocabulary *and* no vocabulary that
 * marks it as configuration or infrastructure. `$currentUser` matches,
 * `$userTable` and `UserRepository` do not.
 */
final class NameHeuristics
{
    /**
     * Vocabulary that suggests state belonging to a single request.
     *
     * @var list<string>
     */
    private const REQUEST_TOKENS = [
        'account',
        'actor',
        'auth',
        'authenticated',
        'bearer',
        'cookie',
        'cookies',
        'credential',
        'credentials',
        'csrf',
        'header',
        'headers',
        'identity',
        'impersonated',
        'jwt',
        'locale',
        'principal',
        'request',
        'response',
        'session',
        'tenant',
        'token',
        'user',
        'users',
        'viewer',
    ];

    /**
     * Vocabulary that turns a request-looking name into configuration or
     * infrastructure. Presence of any of these disqualifies the name.
     *
     * @var list<string>
     */
    private const NEUTRALIZING_TOKENS = [
        'builder',
        'class',
        'classname',
        'column',
        'columns',
        'config',
        'configuration',
        'default',
        'defaults',
        'driver',
        'factory',
        'field',
        'fields',
        'format',
        'formatter',
        'handler',
        'interface',
        'label',
        'labels',
        'logger',
        'manager',
        'map',
        'mapper',
        'mapping',
        'middleware',
        'migration',
        'normalizer',
        'option',
        'options',
        'pattern',
        'policy',
        'prefix',
        'provider',
        'repository',
        'resolver',
        'route',
        'routes',
        'schema',
        'seeder',
        'serializer',
        'service',
        'setting',
        'settings',
        'subscriber',
        'suffix',
        'table',
        'template',
        'transformer',
        'validator',
    ];

    /**
     * Vocabulary that suggests an accumulating structure.
     *
     * @var list<string>
     */
    private const COLLECTION_TOKENS = [
        'buffer',
        'cache',
        'cached',
        'entries',
        'index',
        'instances',
        'items',
        'list',
        'loaded',
        'log',
        'logs',
        'memo',
        'memoized',
        'pool',
        'queue',
        'records',
        'registry',
        'resolved',
        'results',
        'rows',
        'seen',
        'stack',
        'store',
    ];

    /**
     * Method name prefixes that indicate the class can release retained state.
     *
     * @var list<string>
     */
    private const RESET_TOKENS = [
        'clean',
        'cleanup',
        'clear',
        'destroy',
        'flush',
        'forget',
        'gc',
        'invalidate',
        'prune',
        'purge',
        'release',
        'reset',
        'terminate',
        'teardown',
        'truncate',
    ];

    private function __construct()
    {
    }

    /**
     * Split camelCase, PascalCase, snake_case and kebab-case into lowercase tokens.
     *
     * @return list<string>
     */
    public static function tokenize(string $identifier): array
    {
        $parts = preg_split(
            '/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])|(?<=[a-zA-Z])(?=[0-9])|[^a-zA-Z0-9]+/',
            $identifier,
        );

        if ($parts === false) {
            return [strtolower($identifier)];
        }

        $tokens = [];

        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }

            $tokens[] = strtolower($part);
        }

        return $tokens;
    }

    /**
     * True when the identifier reads like state scoped to a single request.
     */
    public static function looksRequestScoped(string $identifier): bool
    {
        return self::requestTokens($identifier) !== [];
    }

    /**
     * The request vocabulary found in the identifier, empty when neutralized.
     *
     * @return list<string>
     */
    public static function requestTokens(string $identifier): array
    {
        $tokens = self::tokenize($identifier);

        if ($tokens === []) {
            return [];
        }

        foreach ($tokens as $token) {
            if (in_array($token, self::NEUTRALIZING_TOKENS, true)) {
                return [];
            }
        }

        $matched = [];

        foreach ($tokens as $token) {
            if (in_array($token, self::REQUEST_TOKENS, true) && !in_array($token, $matched, true)) {
                $matched[] = $token;
            }
        }

        return $matched;
    }

    /**
     * True when any of the given identifiers reads like request scoped state.
     *
     * @param list<string> $identifiers
     */
    public static function anyLooksRequestScoped(array $identifiers): bool
    {
        foreach ($identifiers as $identifier) {
            if (self::looksRequestScoped($identifier)) {
                return true;
            }
        }

        return false;
    }

    public static function looksLikeCollection(string $identifier): bool
    {
        foreach (self::tokenize($identifier) as $token) {
            if (in_array($token, self::COLLECTION_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True for method names such as `reset()`, `flushState()` or `clearCache()`.
     */
    public static function looksLikeReset(string $methodName): bool
    {
        foreach (self::tokenize($methodName) as $token) {
            if (in_array($token, self::RESET_TOKENS, true)) {
                return true;
            }
        }

        return false;
    }
}
