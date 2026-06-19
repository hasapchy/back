<?php

namespace App\Services;

use App\Http\Validation\UserUiPreferencesRules;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class UserUiPreferencesService
{
    /**
     * @return array{preferences: array<string, mixed>, updated_at: int|null}
     */
    public function get(User $user): array
    {
        $preferences = $this->normalizeStored($user->ui_preferences);

        return [
            'preferences' => $preferences,
            'updated_at' => $user->ui_preferences_updated_at !== null
                ? (int) $user->ui_preferences_updated_at
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $patch
     * @return array{preferences: array<string, mixed>, updated_at: int}
     */
    public function patch(User $user, array $patch): array
    {
        $encoded = json_encode($patch);
        if ($encoded !== false && strlen($encoded) > (int) config('ui_preferences.max_patch_bytes', 262144)) {
            throw new InvalidArgumentException(__('api.ui_preferences.patch_too_large'));
        }

        return DB::transaction(function () use ($user, $patch) {
            $user->refresh();
            $current = $this->normalizeStored($user->ui_preferences);
            $next = $current;

            if (array_key_exists('vuex', $patch)) {
                $vuexPatch = UserUiPreferencesRules::filterVuex(
                    is_array($patch['vuex']) ? $patch['vuex'] : null
                );
                if ($vuexPatch !== []) {
                    $next['vuex'] = $this->deepMerge(
                        is_array($next['vuex'] ?? null) ? $next['vuex'] : [],
                        $vuexPatch
                    );
                }
            }

            if (array_key_exists('ls', $patch)) {
                $ls = is_array($next['ls'] ?? null) ? $next['ls'] : [];
                $toSet = [];
                $lsInput = UserUiPreferencesRules::flattenLsPatch(
                    is_array($patch['ls']) ? $patch['ls'] : null
                );

                foreach ($lsInput as $key => $value) {
                    $normalizedKey = (string) $key;
                    if ($value === null) {
                        if (! UserUiPreferencesRules::isAllowedLsKey($normalizedKey, (int) $user->id)) {
                            throw new InvalidArgumentException(__('api.ui_preferences.unknown_ls_key', ['key' => $normalizedKey]));
                        }
                        unset($ls[$normalizedKey]);

                        continue;
                    }
                    $toSet[$normalizedKey] = $value;
                }

                $lsPatch = UserUiPreferencesRules::filterLs($toSet, (int) $user->id);
                foreach ($lsPatch as $key => $value) {
                    $ls[$key] = $value;
                }
                $next['ls'] = $ls;
            }

            $updatedAt = (int) floor(microtime(true) * 1000);
            $user->ui_preferences = $next;
            $user->ui_preferences_updated_at = $updatedAt;
            $user->save();

            return [
                'preferences' => $next,
                'updated_at' => $updatedAt,
            ];
        });
    }

    /**
     * @param  array<string, mixed>|null  $stored
     * @return array<string, mixed>
     */
    private function normalizeStored(?array $stored): array
    {
        $version = (int) config('ui_preferences.schema_version', 1);
        $base = is_array($stored) ? $stored : [];
        $base['v'] = $version;
        if (! isset($base['vuex']) || ! is_array($base['vuex'])) {
            $base['vuex'] = [];
        }
        if (! isset($base['ls']) || ! is_array($base['ls'])) {
            $base['ls'] = [];
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $patch
     * @return array<string, mixed>
     */
    private function deepMerge(array $base, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && isset($base[$key]) && is_array($base[$key]) && Arr::isAssoc($value)) {
                $base[$key] = $this->deepMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }
}
