<?php

namespace App\Support;

trait NaSchRulesTrait
{
    protected function speedup(int $v, int $vMax): int
    {
        return min($v + 1, $vMax);
    }

    protected function slowdown(int $v, int $gap): int
    {
        return min($v, $gap);
    }

    protected function random(int $v, float $p): int
    {
        if ($v > 0 && mt_rand() / mt_getrandmax() < $p) {
            return $v - 1;
        }
        return $v;
    }

    protected function move(int $position, int $speed, int $roadLength): int
    {
        return ($position + $speed) % $roadLength;
    }
}
