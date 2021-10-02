<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

class EnableTwoFactorRequest
{
    #[Assert\NotBlank]
    protected ?string $code = null;

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }
}
