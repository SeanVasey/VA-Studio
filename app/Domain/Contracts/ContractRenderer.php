<?php

namespace App\Domain\Contracts;

interface ContractRenderer
{
    public function render(array $input, array $profile): RenderedContract;
}
