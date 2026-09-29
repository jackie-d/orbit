<?php

namespace App\Enums;

/**
 * How the user felt during / after an interaction.
 */
enum Mood: string
{
    case Great = 'great';
    case Good = 'good';
    case Neutral = 'neutral';
    case Bad = 'bad';
    case Awful = 'awful';

    /**
     * Numeric score (-2..2), handy for averages and trend analysis.
     */
    public function score(): int
    {
        return match ($this) {
            self::Great => 2,
            self::Good => 1,
            self::Neutral => 0,
            self::Bad => -1,
            self::Awful => -2,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
