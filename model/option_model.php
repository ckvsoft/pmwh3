<?php

// modules/pmwh3/model/option_model.php

use ckvsoft\mvc\Model;
use pmwh3\Config\LazyConfig;
use pmwh3\Config\SettingsSchema;
use pmwh3\Config\OptionProviders;
use pmwh3\Config\OnSaveHooks;

class Option_Model extends Model
{

    public function __construct()
    {
        parent::__construct();
        $this->moduleDb = $this->moduleDb();
    }

    /**
     * Return the schema definition merged with the current persisted
     * value and resolved option list, for one key.
     */
    public function getSetting(string $key): ?array
    {
        $def = SettingsSchema::get($key);
        if ($def === null) {
            return null;
        }
        return $this->mergeWithValue($key, $def);
    }

    /** All settings of one section in schema order. */
    public function getGroup(string $group): array
    {
        $defs = SettingsSchema::getByGroup($group);
        $out = [];
        foreach ($defs as $key => $def) {
            $out[$key] = $this->mergeWithValue($key, $def);
        }
        return $out;
    }

    /** All section keys with their localized labels. */
    public function getGroups(): array
    {
        return SettingsSchema::getGroupLabels();
    }

    /**
     * Persist a batch of {key => value} pairs. Validates against
     * the schema, runs any 'on_save' hook.
     *
     * @return array{saved:int, skipped:int, errors:string[]}
     */
    public function saveBatch(array $values): array
    {
        $report = ['saved' => 0, 'skipped' => 0, 'errors' => []];

        foreach ($values as $key => $value) {
            $def = SettingsSchema::get($key);
            if ($def === null) {
                $report['skipped']++;
                continue;
            }

            $error = $this->validate($def, $value);
            if ($error !== null) {
                $report['errors'][] = sprintf('%s: %s', $key, $error);
                $report['skipped']++;
                continue;
            }

            $normalized = $this->normalize($def, $value);
            $oldValue   = LazyConfig::set($key, $normalized);

            if (!empty($def['on_save'])) {
                OnSaveHooks::run((string) $def['on_save'], $key, $normalized, $oldValue);
            }
            $report['saved']++;
        }

        return $report;
    }

    private function validate(array $def, $value): ?string
    {
        $type = $def['type'] ?? 'text';

        switch ($type) {
            case 'int':
                if (!is_numeric($value)) return __('Value must be numeric');
                break;
            case 'checkbox':
                if (!in_array((string) $value, ['Y', 'N'], true)) {
                    return __('Value must be Y or N');
                }
                break;
            case 'select':
                $options = $this->resolveOptions($def);
                if (!array_key_exists((string) $value, $options)) {
                    return __('Value is not in the allowed option list');
                }
                break;
            case 'multi_select':
                if (!is_array($value)) {
                    $value = explode(',', (string) $value);
                }
                $options = $this->resolveOptions($def);
                foreach ($value as $v) {
                    if (!array_key_exists((string) $v, $options)) {
                        return sprintf(__('Value %s is not in the allowed option list'), $v);
                    }
                }
                break;
        }
        return null;
    }

    private function normalize(array $def, $value): string
    {
        $type = $def['type'] ?? 'text';
        if ($type === 'checkbox') {
            return ($value === 'Y' || $value === true || $value === 1 || $value === '1') ? 'Y' : 'N';
        }
        if ($type === 'multi_select' && is_array($value)) {
            return implode(',', $value);
        }
        if ($type === 'int') {
            return (string) (int) $value;
        }
        if ($type === 'textarea') {
            return str_replace(["\r\n", "\r"], "\n", (string) $value);
        }
        return (string) $value;
    }

    private function resolveOptions(array $def): array
    {
        if (isset($def['options']) && is_array($def['options'])) {
            return $def['options'];
        }
        if (!empty($def['options_fn'])) {
            return OptionProviders::resolve((string) $def['options_fn']);
        }
        return [];
    }

    private function mergeWithValue(string $key, array $def): array
    {
        $def['key']     = $key;
        $def['value']   = LazyConfig::get($key, $def['default'] ?? '');
        $def['options'] = $this->resolveOptions($def);
        return $def;
    }
}
