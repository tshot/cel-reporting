<?php

namespace CEL\Shared\Domain\Transformers;

interface TransformerInterface
{
    public function transform(iterable $records): iterable;
}
