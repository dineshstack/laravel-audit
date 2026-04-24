<?php
// src/Http/Controllers/AlertsController.php

namespace Dineshstack\LaravelAudit\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Dineshstack\LaravelAudit\Services\AlertService;

class AlertsController extends Controller
{
    public function __construct(private readonly AlertService $alerts) {}

    private function auth(Request $r): void
    {
        $token = config('audit.token', '');
        if (!$token) return;
        abort_if($r->header('X-Audit-Token') !== $token && $r->bearerToken() !== $token, 401);
    }

    public function index(Request $r)
    {
        $this->auth($r);
        return response()->json([
            'rules'      => $this->alerts->allRules(),
            'defaults'   => $this->alerts->defaultThresholds(),
        ]);
    }

    public function store(Request $r)
    {
        $this->auth($r);
        $validated = $r->validate([
            'name'          => 'required|string|max:100',
            'event_pattern' => 'required|string',
            'metric'        => 'required|string',
            'threshold'     => 'required|integer|min:1',
            'channels'      => 'array',
        ]);
        return response()->json($this->alerts->createRule($validated), 201);
    }

    public function update(Request $r, int $id)
    {
        $this->auth($r);
        $this->alerts->updateRule($id, $r->only(['name','event_pattern','metric','threshold','channels','enabled']));
        return response()->json(['updated' => true]);
    }

    public function destroy(Request $r, int $id)
    {
        $this->auth($r);
        return response()->json(['deleted' => $this->alerts->deleteRule($id)]);
    }

    public function history(Request $r)
    {
        $this->auth($r);
        return response()->json($this->alerts->alertHistory((int) $r->query('limit', 50)));
    }
}
