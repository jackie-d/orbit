<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\LinkExportRequest;
use App\Models\InteractionLink;
use App\Services\InteractionLinkExport;
use App\Services\RecurrenceAnalyzer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only export of interaction links for third-party consumers
 * (analytics tools, notebooks, ...). Requires a token with "links:read".
 */
class InteractionLinkExportController extends Controller
{
    public function __construct(private InteractionLinkExport $export) {}

    /**
     * GET /exports/interaction-links
     *
     *  - format=json   (default) cursor-paginated: { data, meta: { next_cursor, ... } }
     *  - format=ndjson streamed, one JSON document per line, all matching rows
     *  - format=csv    streamed, flat columns, all matching rows
     */
    public function index(LinkExportRequest $request): JsonResponse|StreamedResponse
    {
        $filters = $request->validated();
        $query = $this->export->query($request->user(), $filters);

        return match ($filters['format'] ?? 'json') {
            'ndjson' => $this->ndjson($query),
            'csv' => $this->csv($query),
            default => $this->json($query, (int) ($filters['per_page'] ?? 100), $filters),
        };
    }

    /**
     * GET /exports/interaction-links/recurrence?group_by=target|type|month
     */
    public function recurrence(LinkExportRequest $request, RecurrenceAnalyzer $analyzer): JsonResponse
    {
        $filters = $request->validated();
        $groupBy = $filters['group_by'] ?? 'target';

        $groups = $analyzer->analyze(
            $this->export->query($request->user(), $filters),
            $groupBy,
            (int) ($filters['min_occurrences'] ?? 1),
        );

        return response()->json([
            'data' => $groups,
            'meta' => [
                'group_by' => $groupBy,
                'groups' => count($groups),
                'links' => array_sum(array_column($groups, 'occurrences')),
                'filters' => array_diff_key($filters, array_flip(['format', 'per_page', 'cursor', 'group_by'])),
                'generated_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * @param  Builder<InteractionLink>  $query
     * @param  array<string, mixed>  $filters
     */
    private function json($query, int $perPage, array $filters): JsonResponse
    {
        $page = $query->cursorPaginate($perPage)->withQueryString();

        return response()->json([
            'data' => collect($page->items())->map(fn (InteractionLink $link) => $this->export->row($link))->all(),
            'meta' => [
                'per_page' => $page->perPage(),
                'next_cursor' => $page->nextCursor()?->encode(),
                'prev_cursor' => $page->previousCursor()?->encode(),
                'filters' => array_diff_key($filters, array_flip(['format', 'per_page', 'cursor'])),
            ],
            'links' => [
                'next' => $page->nextPageUrl(),
                'prev' => $page->previousPageUrl(),
            ],
        ]);
    }

    /**
     * @param  Builder<InteractionLink>  $query
     */
    private function ndjson($query): StreamedResponse
    {
        return response()->stream(function () use ($query) {
            foreach ($query->lazyById(500, 'interaction_links.id', 'id') as $link) {
                echo json_encode($this->export->row($link), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Content-Disposition' => 'attachment; filename="interaction-links-'.now()->format('Ymd-His').'.ndjson"',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * @param  Builder<InteractionLink>  $query
     */
    private function csv($query): StreamedResponse
    {
        return response()->stream(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, InteractionLinkExport::CSV_COLUMNS, escape: '');

            foreach ($query->lazyById(500, 'interaction_links.id', 'id') as $link) {
                fputcsv($out, $this->export->csvRow($link), escape: '');
            }

            fclose($out);
        }, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="interaction-links-'.now()->format('Ymd-His').'.csv"',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
