<?php

namespace ModulesGarden\AdminBolt\Helpers;

/**
 * Writes to the WHMCS Module Log without secrets: the server API key and
 * secret, the access hash and account passwords are replaced by asterisks
 * before logging, and their values are also passed to WHMCS to be masked.
 */
class ModuleLog
{
    public const MASK = '********';

    /**
     * Keys whose values are secrets, in module parameters and API bodies.
     */
    public const SECRET_KEYS = ['serverpassword', 'serveraccesshash', 'serverusername', 'password', 'password2', 'api_key', 'api_secret'];

    public static function call(string $action, mixed $request, mixed $response, mixed $processed = null, array $secrets = []): void
    {
        $secrets = self::cleanSecrets($secrets);

        \logModuleCall(
            'AdminBolt',
            $action,
            self::text($request, $secrets),
            self::text($response, $secrets),
            self::text($processed ?? $response, $secrets),
            $secrets
        );
    }

    /**
     * Logs a failed module command with its parameters, secrets removed.
     */
    public static function error(string $action, array $params, string $message): void
    {
        self::call($action, print_r(self::params($params), true), $message, $message, self::secretsOf($params));
    }

    /**
     * The parameters with every secret value replaced by asterisks.
     */
    public static function params(array $params): array
    {
        foreach($params as $key => $value)
        {
            if(is_array($value))
            {
                $params[$key] = self::params($value);
            }
            elseif(is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true) && $value !== '' && $value !== null)
            {
                $params[$key] = self::MASK;
            }
        }

        return $params;
    }

    /**
     * The secret values found in the parameters (for masking free text).
     */
    public static function secretsOf(array $params): array
    {
        $secrets = [];

        foreach($params as $key => $value)
        {
            if(is_array($value))
            {
                $secrets = array_merge($secrets, self::secretsOf($value));
            }
            elseif(is_string($key) && in_array(strtolower($key), self::SECRET_KEYS, true) && is_scalar($value))
            {
                $secrets[] = (string) $value;
            }
        }

        return self::cleanSecrets($secrets);
    }

    /**
     * Free text with API key headers, secret JSON fields and known secret
     * values masked.
     */
    public static function text(mixed $value, array $secrets = []): string
    {
        $text = is_string($value) ? $value : (is_scalar($value) || $value === null ? (string) $value : (string) json_encode($value));

        $text = preg_replace('/^([ \t]*X-API-(?:Key|Secret)[ \t]*:)[^\r\n]*/mi', '$1 ' . self::MASK, $text);

        $keys = implode('|', array_map('preg_quote', self::SECRET_KEYS));
        $text = preg_replace('/("(?:' . $keys . ')"\s*:\s*)"(?:[^"\\\\]|\\\\.)*"/i', '$1"' . self::MASK . '"', $text);

        foreach(self::cleanSecrets($secrets) as $secret)
        {
            $text = str_replace([$secret, substr((string) json_encode($secret), 1, -1)], self::MASK, $text);
        }

        return $text;
    }

    private static function cleanSecrets(array $secrets): array
    {
        $secrets = array_values(array_unique(array_filter(
            array_map('strval', $secrets),
            static fn(string $secret): bool => strlen($secret) >= 4 && $secret !== self::MASK
        )));

        // Values shorter than 4 characters are left to the key-based masking,
        // so they do not garble the log. Longer values first, so a secret containing another is masked whole.
        usort($secrets, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        return $secrets;
    }
}
