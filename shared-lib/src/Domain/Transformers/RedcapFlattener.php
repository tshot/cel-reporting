<?php

namespace CEL\Shared\Domain\Transformers;

class RedcapFlattener implements TransformerInterface
{
    /**
     * Flatten longitudinal + repeating instruments
     * Returns one row per record-event-repeat combination
     */
    private string $primaryKey;

    public function __construct(string $primaryKey)
    {
        if (empty($primaryKey)) 
        {
            throw new InvalidArgumentException(
                "RedcapFlattener requires primaryKey."
            );
        }

        $this->primaryKey = $primaryKey;
    } 
     
    public function transform(iterable $records): \Generator
    {
        foreach ($records as $row) 
        {
			if (!isset($row[$this->primaryKey])) 
			{
                continue;
            }

            $recordId = $row[$this->primaryKey] ;
            
            $event    = $row['redcap_event_name'] ?? null;
            $repeatInstrument = $row['redcap_repeat_instrument'] ?? null;
            $repeatInstance   = $row['redcap_repeat_instance'] ?? null;

            // Compose composite key if needed
            $row['_composite_key'] = implode('|', array_filter([
                $recordId,
                $event,
                $repeatInstrument,
                $repeatInstance
            ]));

            yield $row;
        }
    }
}


/* revisewed 
 * <?php

namespace CEL\Shared\Domain\Transformers;

class RedcapFlattener implements TransformerInterface
{
    public function transform(iterable $records): iterable
    {
        foreach ($records as $row) {
            yield $row;
        }
    }
}
*/
