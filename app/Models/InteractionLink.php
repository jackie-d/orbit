<?php

namespace App\Models;

use App\Enums\LinkType;
use Database\Factories\InteractionLinkFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['type', 'label', 'url', 'note', 'linked_contact_id'])]
class InteractionLink extends Model
{
    /** @use HasFactory<InteractionLinkFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => LinkType::class,
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (InteractionLink $link) {
            $link->user_id ??= $link->interaction?->user_id;
            $link->url_host = static::hostFromUrl($link->url);
        });
    }

    /**
     * Normalized host of a URL ("https://www.Amazon.it/x" => "amazon.it").
     */
    public static function hostFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return preg_replace('/^www\./', '', mb_strtolower($host));
    }

    /**
     * @return BelongsTo<Interaction, $this>
     */
    public function interaction(): BelongsTo
    {
        return $this->belongsTo(Interaction::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Contact, $this>
     */
    public function linkedContact(): BelongsTo
    {
        return $this->belongsTo(Contact::class, 'linked_contact_id');
    }
}
