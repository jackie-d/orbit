<?php

namespace App\Models;

use App\Enums\Mood;
use App\Enums\Outcome;
use Database\Factories\InteractionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'occurred_at', 'title', 'note', 'mood', 'thoughts', 'outcome', 'issues',
    'location_name', 'latitude', 'longitude',
])]
class Interaction extends Model
{
    /** @use HasFactory<InteractionFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'mood' => Mood::class,
            'outcome' => Outcome::class,
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Contact, $this>
     */
    public function contacts(): BelongsToMany
    {
        return $this->belongsToMany(Contact::class)->withPivot('role');
    }

    /**
     * @return HasMany<InteractionLink, $this>
     */
    public function links(): HasMany
    {
        return $this->hasMany(InteractionLink::class);
    }

    /**
     * @param  Builder<Interaction>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $like = '%'.mb_strtolower(trim($term)).'%';

        $query->where(function (Builder $q) use ($like) {
            foreach (['title', 'note', 'thoughts', 'issues', 'location_name'] as $column) {
                $q->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
            }
        });
    }
}
