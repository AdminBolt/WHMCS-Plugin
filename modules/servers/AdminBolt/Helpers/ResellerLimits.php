<?php

namespace ModulesGarden\AdminBolt\Helpers;

/**
 * Resource limits of a reseller product, kept as the product's module
 * settings. The order of LIMITS is the order of the settings, so the n-th
 * entry is read from configoption<n>. Field names are those of the panel API
 * (POST and PUT /api/resellers).
 */
class ResellerLimits
{
    public const LIMITS = [
        'max_accounts' => ['label' => 'Maximum Accounts', 'description' => 'Number of hosting accounts'],
        'disk_limit_mb' => ['label' => 'Disk Limit', 'description' => 'MB'],
        'bandwidth_limit_mb' => ['label' => 'Bandwidth Limit', 'description' => 'MB'],
        'resource_cpu_speed' => ['label' => 'CPU Speed', 'description' => '% (CloudLinux only)'],
        'resource_physical_memory' => ['label' => 'Physical Memory', 'description' => 'MB (CloudLinux only)'],
        'resource_virtual_memory' => ['label' => 'Virtual Memory', 'description' => 'MB (CloudLinux only)'],
        'resource_io' => ['label' => 'I/O Throughput', 'description' => '(CloudLinux only)'],
        'resource_iops' => ['label' => 'I/O Operations', 'description' => '(CloudLinux only)'],
        'resource_processes' => ['label' => 'Processes', 'description' => '(CloudLinux only)'],
        'resource_entry_processes' => ['label' => 'Entry Processes', 'description' => '(CloudLinux only)'],
    ];

    /**
     * The module settings shown on a reseller product.
     */
    public static function configOptions(): array
    {
        $options = [];

        foreach(self::LIMITS as $limit)
        {
            $options[$limit['label']] = [
                'Type' => 'text',
                'Size' => '10',
                'Description' => $limit['description'] . '. Leave empty to keep the panel value.',
            ];
        }

        return $options;
    }

    /**
     * The limits set on the product, keyed by the panel API field. Empty
     * settings are left out, so the panel default applies on create and the
     * current value stays on a package change. A whole number is sent as an
     * integer; anything else is sent as typed so the panel refuses it with
     * its reason.
     */
    public static function fromParams(array $params): array
    {
        $limits = [];
        $index = 1;

        foreach(array_keys(self::LIMITS) as $field)
        {
            $value = trim((string) ($params['configoption' . $index] ?? ''));
            $index++;

            if($value === '')
            {
                continue;
            }

            $limits[$field] = ctype_digit($value) ? (int) $value : $value;
        }

        return $limits;
    }
}
