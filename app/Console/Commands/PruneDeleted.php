<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Interaction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('orbit:prune-deleted {--days= : Override the retention period}')]
#[Description('Permanently delete contacts and interactions soft-deleted more than N days ago')]
class PruneDeleted extends Command
{
    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('orbit.prune_after_days'));
        $cutoff = now()->subDays($days);

        $interactions = 0;
        Interaction::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($chunk) use (&$interactions) {
            $chunk->each->forceDelete();
            $interactions += $chunk->count();
        });

        $contacts = 0;
        Contact::onlyTrashed()->where('deleted_at', '<', $cutoff)->chunkById(200, function ($chunk) use (&$contacts) {
            $chunk->each(function (Contact $contact) {
                Contact::photoDisk()->delete(array_filter([$contact->photo_path, $contact->photo_thumb_path]));
                $contact->forceDelete();
            });
            $contacts += $chunk->count();
        });

        $this->info("Pruned {$contacts} contact(s) and {$interactions} interaction(s) deleted before {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
