<?php
// src/Services/MaskingService.php
// Phase 2: Redacts configured sensitive fields before storage

namespace Dineshstack\LaravelAudit\Services;

class MaskingService
{
    private array $masked;

    public function __construct()
    {
        $configured = config('audit.masked_fields', [
            'password', 'token', 'secret', 'api_key',
            'card_number', 'cvv', 'ssn', 'remember_token',
        ]);

        // Also pick up from AUDIT_MASKED_FIELDS env (comma-separated)
        $env = array_filter(explode(',', env('AUDIT_MASKED_FIELDS', '')));
        $this->masked = array_map('trim', array_merge($configured, $env));
    }

    /**
     * Replace sensitive field values with [REDACTED].
     * Works recursively on nested arrays.
     */
    public function mask(array $attributes): array
    {
        $result = [];
        foreach ($attributes as $key => $value) {
            if ($this->isMasked($key)) {
                $result[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $result[$key] = $this->mask($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function isMasked(string $key): bool
    {
        foreach ($this->masked as $pattern) {
            if (str_contains(strtolower($key), strtolower(trim($pattern)))) {
                return true;
            }
        }
        return false;
    }

    public function addMaskedFields(array $fields): void
    {
        $this->masked = array_unique(array_merge($this->masked, $fields));
    }
}
