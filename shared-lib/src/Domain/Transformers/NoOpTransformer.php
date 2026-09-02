<?php

namespace CEL\Shared\Domain\Transformers;

class NoOpTransformer implements TransformerInterface
{
    public function transform(iterable $records): iterable
    {
        return $records;
    }
}
