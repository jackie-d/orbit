<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ContactRequest;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Services\ContactWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class ContactController extends Controller
{
    private const DETAILS = ['phoneNumbers', 'emails', 'urls', 'addresses'];

    private const SORTABLE = ['first_name', 'last_name', 'created_at', 'updated_at'];

    public function __construct(private ContactWriter $writer) {}

    /**
     * GET /contacts?q=&favorite=1&sort=-created_at&per_page=25
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'favorite' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $query = $request->user()->contacts()
            ->with(self::DETAILS)
            ->withCount('interactions')
            ->withMax('interactions', 'occurred_at')
            ->search($request->input('q'))
            ->when($request->has('favorite'), fn ($q) => $q->where('is_favorite', $request->boolean('favorite')));

        $sort = $request->input('sort', 'first_name');
        $column = ltrim($sort, '-');
        if (in_array($column, self::SORTABLE, true)) {
            $query->orderBy($column, str_starts_with($sort, '-') ? 'desc' : 'asc');
        }
        $query->orderBy('id');

        return ContactResource::collection($query->paginate($request->integer('per_page', 25))->withQueryString());
    }

    public function store(ContactRequest $request): Response
    {
        $contact = $this->writer->create($request->user(), $request->payload());

        return (new ContactResource($contact->load(self::DETAILS)))->response()->setStatusCode(Response::HTTP_CREATED);
    }

    public function show(Contact $contact): ContactResource
    {
        return new ContactResource(
            $contact->load(self::DETAILS)->loadCount('interactions')->loadMax('interactions', 'occurred_at')
        );
    }

    public function update(ContactRequest $request, Contact $contact): ContactResource
    {
        $contact = $this->writer->update($contact, $request->payload());

        return new ContactResource($contact->load(self::DETAILS));
    }

    public function destroy(Contact $contact): Response
    {
        $contact->delete();

        return response()->noContent();
    }
}
