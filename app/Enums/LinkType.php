<?php

namespace App\Enums;

/**
 * What an interaction link points to.
 */
enum LinkType: string
{
    case Service = 'service';
    case Shop = 'shop';
    case Person = 'person';
    case Other = 'other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
