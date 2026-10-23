<?php

namespace App\Services;

class MockInsurerService
{
    public function issuePolicy(array $data): array
    {
        return [
            'success' => true,
            'provider_reference' => 'MOCK-' . strtoupper(uniqid()),
            'policy_number' => 'POL-' . strtoupper(bin2hex(random_bytes(5))),
            'message' => 'Policy issued successfully by mock insurer.',
        ];
    }
}
