<?php

namespace App\Contact;

interface ContactInterface
{
    public function getName(): string;

    public function getEmail(): ?string;
}
