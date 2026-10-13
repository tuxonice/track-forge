<?php

namespace App\Exception;

class RouteSuggestionException extends \RuntimeException
{
    /** @param array<string, string|int> $parameters */
    public function __construct(string $translationKey, private readonly array $parameters = [])
    {
        parent::__construct($translationKey);
    }

    /** @return array<string, string|int> */
    public function getParameters(): array
    {
        return $this->parameters;
    }
}
