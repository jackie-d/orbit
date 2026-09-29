<?php

namespace App\Enums;

/**
 * Overall result of an interaction.
 */
enum Outcome: string
{
    case Positive = 'positive';
    case Neutral = 'neutral';
    case Negative = 'negative';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
