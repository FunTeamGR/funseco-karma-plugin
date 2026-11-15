<?php
namespace Funseco\Karma;

/**
 * Vote counts per level and the karma value (XAseco mk_calculateKarma).
 */
final class KarmaTally
{
    private const WEIGHTS = [3 => 100, 2 => 80, 1 => 60, -1 => 40, -2 => 20, -3 => 0];

    /** @param array<int, int> $counts score => count */
    private function __construct(private array $counts, private ?int $karma = null)
    {
    }

    public static function fromScores(array $scores): self
    {
        $counts = array_fill_keys(array_keys(self::WEIGHTS), 0);
        foreach ($scores as $score) {
            if (isset($counts[$score])) {
                $counts[$score]++;
            }
        }

        return new self($counts);
    }

    /** From mania-karma.com, which sends its own karma value. */
    public static function fromService(array $counts, int $karma): self
    {
        return new self(array_replace(array_fill_keys(array_keys(self::WEIGHTS), 0), $counts), $karma);
    }

    /** One player's vote changed from $old (null = none) to $new. */
    public function moved(?int $old, int $new): self
    {
        $counts = $this->counts;
        if ($old !== null && isset($counts[$old])) {
            $counts[$old] = max(0, $counts[$old] - 1);
        }
        $counts[$new]++;

        return new self($counts);   // recomputed, the service's value is stale now
    }

    public function count(int $score): int
    {
        return $this->counts[$score] ?? 0;
    }

    public function total(): int
    {
        return array_sum($this->counts);
    }

    public function percent(int $score): string
    {
        $total = $this->total();

        return sprintf('%.2f', $total ? $this->count($score) / $total * 100 : 0);
    }

    public function karma(): int
    {
        if ($this->karma !== null) {
            return $this->karma;
        }
        if (config('karma.calculation') === 'rasp') {
            return array_sum(array_map(fn ($score, $count) => $score * $count, array_keys($this->counts), $this->counts));
        }
        $total = $this->total();

        return $total ? (int) floor(array_sum(array_map(fn ($score, $count) => self::WEIGHTS[$score] * $count, array_keys($this->counts), $this->counts)) / $total) : 0;
    }
}
