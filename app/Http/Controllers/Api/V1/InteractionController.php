<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Mood;
use App\Enums\Outcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\InteractionRequest;
use App\Http\Resources\InteractionResource;
use App\Models\Contact;
use App\Models\Interaction;
use App\Services\InteractionWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class InteractionController extends Controller
{
    private const RELATIONS = ['contacts', 'links.linkedContact'];

    public function __construct(private InteractionWriter $writer) {}

    /**
     * GET /interactions?contact_id=&from=&to=&mood=&outcome=&q=&per_page=
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        return $this->list($request, $request->user()->interactions()->getQuery());
    }

    /**
     * GET /contacts/{contact}/interactions — a contact's timeline.
     */
    public function forContact(Request $request, Contact $contact): AnonymousResourceCollection
    {
        return $this->list(
            $request,
            $request->user()->interactions()->getQuery()->whereHas('contacts', fn (Builder $q) => $q->whereKey($contact->id)),
        );
    }

    public function store(InteractionRequest $request): JsonResponse
    {
        $interaction = $this->writer->create($request->user(), $request->validated());

        return (new InteractionResource($interaction->load(self::RELATIONS)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Interaction $interaction): InteractionResource
    {
        return new InteractionResource($interaction->load(self::RELATIONS));
    }

    public function update(InteractionRequest $request, Interaction $interaction): InteractionResource
    {
        $interaction = $this->writer->update($interaction, $request->validated());

        return new InteractionResource($interaction->load(self::RELATIONS));
    }

    public function destroy(Interaction $interaction): Response
    {
        $interaction->delete();

        return response()->noContent();
    }

    /**
     * @param  Builder<Interaction>  $query
     */
    private function list(Request $request, Builder $query): AnonymousResourceCollection
    {
        $request->validate([
            'contact_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'mood' => ['nullable', Rule::enum(Mood::class)],
            'outcome' => ['nullable', Rule::enum(Outcome::class)],
            'q' => ['nullable', 'string', 'max:255'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query
            ->with(self::RELATIONS)
            ->when($request->filled('contact_id'), fn (Builder $q) => $q->whereHas('contacts', fn (Builder $c) => $c->whereKey($request->integer('contact_id'))))
            ->when($request->filled('from'), fn (Builder $q) => $q->where('occurred_at', '>=', $request->date('from')->setTimezone(config('app.timezone'))))
            ->when($request->filled('to'), fn (Builder $q) => $q->where('occurred_at', '<=', $request->date('to')->setTimezone(config('app.timezone'))))
            ->when($request->filled('mood'), fn (Builder $q) => $q->where('mood', $request->input('mood')))
            ->when($request->filled('outcome'), fn (Builder $q) => $q->where('outcome', $request->input('outcome')))
            ->search($request->input('q'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('interactions.id');

        return InteractionResource::collection($query->paginate($request->integer('per_page', 25))->withQueryString());
    }
}
