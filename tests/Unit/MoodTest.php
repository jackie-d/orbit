<?php

namespace Tests\Unit;

use App\Enums\Mood;
use PHPUnit\Framework\TestCase;

class MoodTest extends TestCase
{
    public function test_moods_map_to_a_symmetric_score(): void
    {
        $this->assertSame(
            ['great' => 2, 'good' => 1, 'neutral' => 0, 'bad' => -1, 'awful' => -2],
            array_combine(Mood::values(), array_map(fn (Mood $mood) => $mood->score(), Mood::cases())),
        );
    }
}
