<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class StatsController extends Controller
{
    /**
     * GET /stats/overview — cached briefly (Redis in Kubernetes).
     */
    public function overview(Request $request): JsonResponse
    {
        $user = $request->user();

        $stats = Cache::remember("stats:overview:{$user->id}", now()->addMinute(), function () use ($user) {
            $interactions = $user->interactions();

            return [
                'contacts' => $user->contacts()->count(),
                'favorites' => $user->contacts()->where('is_favorite', true)->count(),
                'interactions' => (clone $interactions)->count(),
                'interactions_last_30_days' => (clone $interactions)->where('occurred_at', '>=', now()->subDays(30))->count(),
                'by_mood' => (clone $interactions)->whereNotNull('mood')->toBase()
                    ->selectRaw('mood, COUNT(*) as total')->groupBy('mood')->pluck('total', 'mood')->map(fn ($n) => (int) $n),
                'by_outcome' => (clone $interactions)->whereNotNull('outcome')->toBase()
                    ->selectRaw('outcome, COUNT(*) as total')->groupBy('outcome')->pluck('total', 'outcome')->map(fn ($n) => (int) $n),
                'top_contacts' => $user->contacts()
                    ->whereHas('interactions')
                    ->withCount('interactions')
                    ->orderByDesc('interactions_count')
                    ->limit(5)
                    ->get()
                    ->map(fn (Contact $c) => ['id' => $c->id, 'full_name' => $c->full_name, 'interactions' => $c->interactions_count]),
                'generated_at' => now()->toIso8601String(),
            ];
        });

        return response()->json(['data' => $stats]);
    }
}
