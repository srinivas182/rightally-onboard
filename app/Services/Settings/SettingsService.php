<?php

namespace App\Services\Settings;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;

/**
 * Single entry point for admin-editable settings.
 *
 * - Values are cached (one cache entry for all settings) and the cache is
 *   cleared on every write.
 * - Secret fields are encrypted with APP_KEY before they reach the database
 *   and are never logged or sent back to the browser in full.
 */
class SettingsService
{
    private const CACHE_KEY = 'rightally.settings.v1';

    /** @var array<string, array<string, ?string>>|null */
    private ?array $loaded = null;

    public function get(string $group, string $key, mixed $default = null): mixed
    {
        if (! SettingsSchema::exists($group, $key)) {
            throw new InvalidArgumentException("Unknown setting {$group}.{$key}");
        }

        $all = $this->all();
        $value = $all[$group][$key] ?? null;

        if ($value === null || $value === '') {
            return $default ?? SettingsSchema::groups()[$group]['fields'][$key]['default'];
        }

        return $value;
    }

    /** @return array<string, ?string> */
    public function group(string $group): array
    {
        $out = [];
        foreach (array_keys(SettingsSchema::groups()[$group]['fields'] ?? []) as $key) {
            $out[$key] = $this->get($group, $key);
        }

        return $out;
    }

    /**
     * Save several values in one group.
     * For secret fields an empty value means "keep the stored value".
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string> keys that actually changed
     */
    public function setMany(string $group, array $values, ?int $adminId = null): array
    {
        $changed = [];

        foreach ($values as $key => $value) {
            if (! SettingsSchema::exists($group, $key)) {
                continue;
            }
            $secret = SettingsSchema::isSecret($group, $key);
            if ($secret && ($value === null || $value === '')) {
                continue;
            }

            $value = $value === null ? null : (string) $value;
            if ($this->get($group, $key) === $value) {
                continue;
            }

            Setting::updateOrCreate(
                ['group' => $group, 'key' => $key],
                [
                    'value' => $secret && $value !== null ? Crypt::encryptString($value) : $value,
                    'is_encrypted' => $secret,
                    'updated_by' => $adminId,
                ],
            );
            $changed[] = $key;
        }

        $this->flush();

        return $changed;
    }

    public function set(string $group, string $key, mixed $value, ?int $adminId = null): void
    {
        $this->setMany($group, [$key => $value], $adminId);
    }

    /** Clear a secret (e.g. remove live keys). */
    public function forget(string $group, string $key): void
    {
        Setting::where(['group' => $group, 'key' => $key])->delete();
        $this->flush();
    }

    /** Last four characters of a secret, for display only. */
    public function mask(string $group, string $key): ?string
    {
        $value = $this->get($group, $key);

        return $value ? '••••'.substr($value, -4) : null;
    }

    public function flush(): void
    {
        $this->loaded = null;
        Cache::forget(self::CACHE_KEY);
    }

    /**
     * Settings are cached still encrypted; decryption happens in memory.
     *
     * @return array<string, array<string, ?string>>
     */
    private function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        $rows = Cache::rememberForever(self::CACHE_KEY, fn () => Setting::query()
            ->get(['group', 'key', 'value', 'is_encrypted'])
            ->map(fn (Setting $s) => [$s->group, $s->key, $s->value, $s->is_encrypted])
            ->all());

        $out = [];
        foreach ($rows as [$group, $key, $value, $encrypted]) {
            $out[$group][$key] = ($encrypted && $value !== null) ? Crypt::decryptString($value) : $value;
        }

        return $this->loaded = $out;
    }
}
