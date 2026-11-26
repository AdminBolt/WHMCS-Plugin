<?php

namespace ModulesGarden\AdminBolt\Helpers;

/**
 * Plugin features sold as configurable options.
 *
 * In AdminBolt, what a plugin offers hosting customers is a feature of their
 * plan, with the code "plugin:<plugin id>" (for example "plugin:matomo"). A
 * configurable option whose name is that code, such as
 * "plugin:matomo|Website statistics", switches it on for the service's
 * account when the customer buys it and off when they do not, on top of
 * what the hosting plan includes.
 */
class PluginFeatures
{
    public const PREFIX = 'plugin:';

    /**
     * The plugin features the service's configurable options set, keyed by
     * feature code: true when bought, false when not. Options with any other
     * name are left alone.
     */
    public static function fromParams(array $params): array
    {
        $features = [];

        foreach((array) ($params['configoptions'] ?? []) as $name => $value)
        {
            $code = trim((string) $name);

            if(!str_starts_with($code, self::PREFIX) || $code === self::PREFIX)
            {
                continue;
            }

            $features[$code] = self::isOn($value);
        }

        return $features;
    }

    /**
     * A Yes/No option is "1" or "0", a quantity is a number, and a dropdown
     * is the name of the chosen option, so "Yes" or "No".
     */
    private static function isOn(mixed $value): bool
    {
        if(is_bool($value))
        {
            return $value;
        }

        $value = strtolower(trim((string) $value));

        if(is_numeric($value))
        {
            return (float) $value > 0;
        }

        return in_array($value, ['yes', 'on', 'true', 'enabled'], true);
    }
}
