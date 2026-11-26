<?php

namespace ModulesGarden\AdminBolt\Helpers;

use Exception;

/**
 * The Hosting Plan module setting of a shared hosting product
 * (configoption1). Plan IDs are local to each panel, so the setting holds
 * the plan name with the prefix "name:", and Create and Change Package send
 * the name; every AdminBolt server of the product's group then uses its own
 * plan of that name. The name is stored URL-encoded (rawurlencode), so the
 * stored value has no character WHMCS could store or render as an HTML
 * entity, and the dropdown always finds the saved option again.
 *
 * Module 0.0.5 and older stored the plan ID of one server (digits only).
 * Such a value is never sent: on another server the same ID can be another
 * plan, and which server it was read from is not recorded. The command
 * stops until the product is saved with a plan chosen by name. The prefix
 * keeps the two forms apart without guessing: a plan named "3" is stored
 * as "name:3".
 */
class HostingPlanSetting
{
    public const NAME_PREFIX = 'name:';

    /** The value stored for a plan chosen by name. */
    public static function forName(string $name): string
    {
        return self::NAME_PREFIX . rawurlencode($name);
    }

    /** The plan name of a stored value with the prefix, or null. */
    public static function storedName(mixed $stored): ?string
    {
        $stored = (string) $stored;

        return str_starts_with($stored, self::NAME_PREFIX) ? rawurldecode(substr($stored, strlen(self::NAME_PREFIX))) : null;
    }

    /** A plan ID stored by module 0.0.5 or older, or null for any other value. */
    public static function legacyPlanId(mixed $stored): ?int
    {
        $stored = trim((string) $stored);

        return $stored !== '' && ctype_digit($stored) ? (int) $stored : null;
    }

    /**
     * The plan name to send, from the stored setting. Fails, before any
     * panel call, for an empty setting, a plan ID stored by module 0.0.5
     * and any other value without the prefix.
     */
    public static function nameFrom(mixed $stored): string
    {
        $stored = (string) $stored;
        $legacyId = self::legacyPlanId($stored);

        if($legacyId !== null)
        {
            throw new Exception(sprintf(
                'This product\'s Hosting Plan setting still holds the plan ID %d saved by AdminBolt module 0.0.5 or older. Plan IDs differ between AdminBolt servers, so the module sends the plan by name and does not send this ID. Nothing was changed in the panel. Open the product\'s Module Settings, choose the hosting plan by name and save.',
                $legacyId
            ));
        }

        if(str_starts_with($stored, self::NAME_PREFIX))
        {
            $name = trim(rawurldecode(substr($stored, strlen(self::NAME_PREFIX))));

            if($name !== '')
            {
                return $name;
            }
        }

        if(trim($stored) === '' || str_starts_with($stored, self::NAME_PREFIX))
        {
            throw new Exception('This product has no hosting plan chosen. Nothing was changed in the panel. Open the product\'s Module Settings, choose the hosting plan and save.');
        }

        throw new Exception(sprintf(
            'This product\'s Hosting Plan setting holds "%s", which is not a hosting plan chosen in the module settings. Nothing was changed in the panel. Open the product\'s Module Settings, choose the hosting plan and save.',
            $stored
        ));
    }
}
