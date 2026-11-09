<?php

declare(strict_types=1);

namespace Nucleo\OAuth;

/** Token OAuth della richiesta in corso. Null significa richiesta non OAuth. */
final class CurrentToken
{
    /** @var array{user_id:int,client_id:string,scopes:list<string>}|null */
    private static ?array $token = null;

    /** @param array{user_id:int,client_id:string,scopes:list<string>} $token */
    public static function set(array $token): void
    {
        self::$token = $token;
    }

    /** @return list<string>|null */
    public static function scopes(): ?array
    {
        return self::$token['scopes'] ?? null;
    }

    public static function clientId(): ?string
    {
        return self::$token['client_id'] ?? null;
    }

    public static function clear(): void
    {
        self::$token = null;
    }
}
