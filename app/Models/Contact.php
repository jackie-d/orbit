<?php

namespace App\Models;

use Database\Factories\ContactFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'first_name', 'last_name', 'nickname', 'company', 'job_title',
    'birthday', 'notes', 'is_favorite',
])]
class Contact extends Model
{
    /** @use HasFactory<ContactFactory> */
    use HasFactory, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'is_favorite' => 'boolean',
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
     * @return HasMany<ContactPhoneNumber, $this>
     */
    public function phoneNumbers(): HasMany
    {
        return $this->hasMany(ContactPhoneNumber::class);
    }

    /**
     * @return HasMany<ContactEmail, $this>
     */
    public function emails(): HasMany
    {
        return $this->hasMany(ContactEmail::class);
    }

    /**
     * @return HasMany<ContactUrl, $this>
     */
    public function urls(): HasMany
    {
        return $this->hasMany(ContactUrl::class);
    }

    /**
     * @return HasMany<ContactAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(ContactAddress::class);
    }

    /**
     * @return BelongsToMany<Interaction, $this>
     */
    public function interactions(): BelongsToMany
    {
        return $this->belongsToMany(Interaction::class)->withPivot('role');
    }

    /**
     * Interaction links (of any interaction) that point to this contact.
     *
     * @return HasMany<InteractionLink, $this>
     */
    public function mentions(): HasMany
    {
        return $this->hasMany(InteractionLink::class, 'linked_contact_id');
    }

    /**
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    /**
     * Case-insensitive search across names, company and contact details.
     *
     * @param  Builder<Contact>  $query
     */
    public function scopeSearch(Builder $query, ?string $term): void
    {
        if ($term === null || trim($term) === '') {
            return;
        }

        $like = '%'.mb_strtolower(trim($term)).'%';

        $query->where(function (Builder $q) use ($like) {
            foreach (['first_name', 'last_name', 'nickname', 'company'] as $column) {
                $q->orWhereRaw("LOWER({$column}) LIKE ?", [$like]);
            }

            $q->orWhereHas('emails', fn (Builder $e) => $e->whereRaw('LOWER(email) LIKE ?', [$like]))
                ->orWhereHas('phoneNumbers', fn (Builder $p) => $p->where('number', 'like', $like));
        });
    }

    public static function photoDisk(): Filesystem
    {
        return Storage::disk(config('orbit.photos.disk'));
    }

    public function photoUrl(bool $thumbnail = false): ?string
    {
        $path = $thumbnail ? ($this->photo_thumb_path ?? $this->photo_path) : $this->photo_path;

        if ($path === null) {
            return null;
        }

        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = static::photoDisk();

        // Private object stores (S3/MinIO) get short-lived signed URLs.
        if ($disk->providesTemporaryUrls() && config('filesystems.disks.'.config('orbit.photos.disk').'.driver') === 's3') {
            return $disk->temporaryUrl($path, now()->addMinutes(config('orbit.photos.url_ttl_minutes')));
        }

        return $disk->url($path);
    }
}
