<?php

namespace App\Services\Ai;

use App\Models\User;

class AiClient
{
    protected ?User $actingUser = null;

    public function forUser(?User $user): self
    {
        $clone = clone $this;
        $clone->actingUser = $user;

        return $clone;
    }

    public function embed(string $text, string $context): array
    {
        throw new \LogicException('AiClient::embed not yet implemented — see Plan 2 Task 3.');
    }

    public function parseResume(string $rawText): array
    {
        throw new \LogicException('AiClient::parseResume not yet implemented — see Plan 2 Task 3.');
    }
}
