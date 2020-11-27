<?php

namespace App\Form\Model;

use Symfony\Component\Validator\Constraints as Assert;

class EnableTwoFactorRequest
{
    /**
     * @var string
     * @Assert\NotBlank
     */
    protected $secret;

    /**
     * @var ?string
     * @Assert\NotBlank
     */
    protected $code;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function getSecret(): string
    {
        return $this->secret;
    }

    public function setSecret(string $secret): void
    {
        $this->secret = $secret;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): void
    {
        $this->code = $code;
    }
}