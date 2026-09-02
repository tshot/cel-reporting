<?php

namespace CEL\Shared\Domain\Aggregator;

class Aggregator
{
    public function groupCount(
        array $records,
        string $groupField,
        callable $rule
    ): array 
    {

        $result = [];

        foreach ($records as $record) 
        {

            if (!$rule($record)) 
            {
                continue;
            }

            $group = $record[$groupField] ?? 'UNKNOWN';

            if (!isset($result[$group])) 
            {
                $result[$group] = 0;
            }

            $result[$group]++;
        }

        return $result;
    }
}
