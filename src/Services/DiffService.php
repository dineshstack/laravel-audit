<?php
// src/Services/DiffService.php
// Phase 2: Computes structured diff between old and new model attributes

namespace Dineshstack\LaravelAudit\Services;

class DiffService
{
    /**
     * Compute a structured diff between two attribute arrays.
     * Returns array of changed fields with old/new values.
     *
     * [
     *   'name'   => ['old' => 'John', 'new' => 'Jane'],
     *   'status' => ['old' => 'pending', 'new' => 'active'],
     * ]
     */
    public function compute(array $old, array $new): array
    {
        $diff = [];

        // Fields changed or added
        foreach ($new as $key => $newVal) {
            $oldVal = $old[$key] ?? null;
            if ($oldVal !== $newVal) {
                $diff[$key] = [
                    'old' => $oldVal,
                    'new' => $newVal,
                    'type' => $this->changeType($oldVal, $newVal),
                ];
            }
        }

        // Fields removed
        foreach ($old as $key => $oldVal) {
            if (!array_key_exists($key, $new)) {
                $diff[$key] = [
                    'old'  => $oldVal,
                    'new'  => null,
                    'type' => 'removed',
                ];
            }
        }

        return $diff;
    }

    /**
     * Render diff as human-readable summary for descriptions.
     * e.g. "Changed name, status"
     */
    public function summarise(array $diff): string
    {
        if (empty($diff)) return 'No changes';
        $fields = array_keys($diff);
        return 'Changed '.implode(', ', array_slice($fields, 0, 5))
            .(count($fields) > 5 ? ' and '.(count($fields) - 5).' more' : '');
    }

    private function changeType(mixed $old, mixed $new): string
    {
        if ($old === null) return 'added';
        if ($new === null) return 'removed';
        return 'changed';
    }
}
