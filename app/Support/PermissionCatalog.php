<?php

namespace App\Support;

/** Reads config/permissions.php and applies the role-editing rules the admin panel uses (lib/permissions.ts). */
class PermissionCatalog
{
    /** @return list<array{key: string, label: string, description: string, actions: list<string>, special: array{action: string, label: string}|null}> */
    public static function modules(): array
    {
        return config('permissions.modules');
    }

    /** Every key of one module: its actions, then its special permission. @return list<string> */
    public static function moduleKeys(array $module): array
    {
        $keys = array_map(fn (string $action) => "{$module['key']}.{$action}", $module['actions']);
        if ($module['special']) {
            $keys[] = "{$module['key']}.{$module['special']['action']}";
        }

        return $keys;
    }

    /** Every permission name, in catalog order. @return list<string> */
    public static function keys(): array
    {
        return array_merge(...array_map(self::moduleKeys(...), self::modules()));
    }

    /** Whether only a Super Admin may grant this permission. */
    public static function isSuperAdminOnly(string $key): bool
    {
        return in_array(strstr($key, '.', true), config('permissions.super_admin_only_modules'), true);
    }

    /**
     * Drops duplicates, adds the `view` permission of every module that has any other permission granted,
     * and sorts into catalog order (so two grants can be compared directly). Unknown names are ignored:
     * validate them first.
     *
     * @param  iterable<string>  $granted
     * @return list<string>
     */
    public static function normalize(iterable $granted): array
    {
        $set = array_flip([...$granted]);

        foreach (self::modules() as $module) {
            $keys = self::moduleKeys($module);
            if (in_array('view', $module['actions'], true) && array_intersect_key($set, array_flip($keys))) {
                $set["{$module['key']}.view"] = true;
            }
        }

        return array_values(array_filter(self::keys(), fn (string $key) => isset($set[$key])));
    }
}
