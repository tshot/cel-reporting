<?php

namespace CEL\Shared\Domain\Transformers;

use InvalidArgumentException;

class TransformerFactory
{
    public static function create(
        string $mode,
        array $options = []
    ): TransformerInterface 
    {

        return match ($mode) 
        {

            'wide' => self::createWide($options),

            'flat' => self::createFlat($options),

            'raw'  => new NoOpTransformer(),

            default => throw new InvalidArgumentException(
                "Unknown transformer mode: {$mode}"
            ),
        };
    }

    private static function createWide(array $options): WidePivotTransformer
	{
		if (empty($options['columns'])) 
		{
			throw new \InvalidArgumentException(
				"Wide mode requires 'columns'."
			);
		}

		if (empty($options['fieldToForm'])) 
		{
			throw new \InvalidArgumentException(
				"Wide mode requires 'fieldToForm'."
			);
		}

		if (empty($options['primaryKey'])) 
		{
			throw new \InvalidArgumentException(
				"Wide mode requires 'primaryKey'."
			);
		}

		return new WidePivotTransformer(
			$options['columns'],
			$options['fieldToForm'],
			$options['primaryKey'],
			$options['filterNonempty'] ?? [],
			$options['selectFields']   ?? [],
			$options['skipRepeats']    ?? false
		);
	}
	
	private static function createFlat(array $options): RedcapFlattener
	{
		if (empty($options['primaryKey'])) 
		{
			throw new \InvalidArgumentException(
				"Flat mode requires 'primaryKey'."
			);
		}

		return new RedcapFlattener(
			$options['primaryKey']
		);
	}

}
