<?php

namespace App\Services;

use App\Models\InteractionLink;
use Illuminate\Database\Eloquent\Builder;

/**
 * Aggregates interaction links to show how often real-life interactions
 * lead "outbound" to the same service, shop or person.
 *
 * Links are streamed in chunks and folded into per-group accumulators, so
 * memory grows with the number of distinct targets, not with the number of links.
 */
class RecurrenceAnalyzer
{
    public const GROUP_BY = ['target', 'type', 'month'];

    /**
     * @param  Builder<InteractionLink>  $query
     * @return list<array<string, mixed>>
     */
    public function analyze(Builder $query, string $groupBy = 'target', int $minOccurrences = 1): array
    {
        $groups = [];

        foreach ($query->lazyById(1000, 'interaction_links.id', 'id') as $link) {
            $key = $this->keyFor($link, $groupBy);
            $interaction = $link->interaction;
            $day = $interaction->occurred_at->toDateString();

            $group = $groups[$key] ?? [
                'key' => $key,
                'type' => null,
                'types' => [],
                'label' => null,
                'url_host' => null,
                'linked_contact' => null,
                'occurrences' => 0,
                'interaction_ids' => [],
                'days' => [],
                'first_seen' => null,
                'last_seen' => null,
                'mood_counts' => [],
                'mood_score_sum' => 0,
                'mood_score_n' => 0,
                'outcome_counts' => [],
            ];

            $group['occurrences']++;
            $group['interaction_ids'][$interaction->id] = true;
            $group['days'][$day] = true;
            $group['types'][$link->type->value] = ($group['types'][$link->type->value] ?? 0) + 1;

            $occurredAt = $interaction->occurred_at;
            if ($group['first_seen'] === null || $occurredAt->lt($group['first_seen'])) {
                $group['first_seen'] = $occurredAt;
            }
            if ($group['last_seen'] === null || $occurredAt->gte($group['last_seen'])) {
                $group['last_seen'] = $occurredAt;
                // Most recent naming wins.
                $group['label'] = $link->label;
                $group['url_host'] = $link->url_host;
                $group['linked_contact'] = $link->linkedContact
                    ? ['id' => $link->linkedContact->id, 'name' => $link->linkedContact->full_name]
                    : null;
            }

            if ($interaction->mood) {
                $mood = $interaction->mood->value;
                $group['mood_counts'][$mood] = ($group['mood_counts'][$mood] ?? 0) + 1;
                $group['mood_score_sum'] += $interaction->mood->score();
                $group['mood_score_n']++;
            }

            if ($interaction->outcome) {
                $outcome = $interaction->outcome->value;
                $group['outcome_counts'][$outcome] = ($group['outcome_counts'][$outcome] ?? 0) + 1;
            }

            $groups[$key] = $group;
        }

        return collect($groups)
            ->filter(fn (array $g) => $g['occurrences'] >= $minOccurrences)
            ->map(fn (array $g) => $this->finalize($g, $groupBy))
            ->sortBy([['occurrences', 'desc'], ['last_seen', 'desc']])
            ->values()
            ->all();
    }

    private function keyFor(InteractionLink $link, string $groupBy): string
    {
        return match ($groupBy) {
            'type' => 'type:'.$link->type->value,
            'month' => 'month:'.$link->interaction->occurred_at->format('Y-m'),
            default => match (true) {
                $link->linked_contact_id !== null => 'contact:'.$link->linked_contact_id,
                $link->url_host !== null => 'host:'.$link->url_host,
                default => 'label:'.mb_strtolower(preg_replace('/\s+/', ' ', trim($link->label))),
            },
        };
    }

    /**
     * @param  array<string, mixed>  $g
     * @return array<string, mixed>
     */
    private function finalize(array $g, string $groupBy): array
    {
        $days = array_keys($g['days']);
        sort($days);

        $avgGap = null;
        if (count($days) > 1) {
            $first = new \DateTimeImmutable($days[0]);
            $last = new \DateTimeImmutable(end($days));
            $avgGap = round($first->diff($last)->days / (count($days) - 1), 2);
        }

        arsort($g['types']);

        $result = [
            'key' => $g['key'],
            'type' => array_key_first($g['types']),
        ];

        if ($groupBy === 'target') {
            $result += [
                'label' => $g['label'],
                'url_host' => $g['url_host'],
                'linked_contact' => $g['linked_contact'],
            ];
        } else {
            $result['types'] = $g['types'];
        }

        return $result + [
            'occurrences' => $g['occurrences'],
            'interactions' => count($g['interaction_ids']),
            'distinct_days' => count($days),
            'first_seen' => $g['first_seen']->toIso8601String(),
            'last_seen' => $g['last_seen']->toIso8601String(),
            'avg_days_between' => $avgGap,
            'mood_counts' => $g['mood_counts'],
            'avg_mood_score' => $g['mood_score_n'] > 0 ? round($g['mood_score_sum'] / $g['mood_score_n'], 2) : null,
            'outcome_counts' => $g['outcome_counts'],
        ];
    }
}
