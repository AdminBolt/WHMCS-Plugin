<?php

/**
 * ApiException::getPanelReason(): the panel's reason with every field error
 * once. Run: php tests/ApiExceptionTest.php
 */

require_once __DIR__ . '/bootstrap.php';

use ModulesGarden\AdminBolt\Api\ApiException;

tests_reset();

// 1. Laravel summary "<first error> (and N more errors)" plus the same errors per field.
$reason = (new ApiException('API error: x', 422, [
    'message' => 'The reseller already has 20 accounts. (and 2 more errors)',
    'errors' => [
        'max_accounts' => ['The reseller already has 20 accounts.', 'The reseller already has 20 accounts.'],
        'disk_limit_mb' => ['The disk limit is below the allocated 4096 MB.'],
    ],
]))->getPanelReason();
check($reason === 'API error (HTTP 422): max_accounts: The reseller already has 20 accounts. disk_limit_mb: The disk limit is below the allocated 4096 MB.', 'each field error once, no summary: ' . $reason);
check(substr_count($reason, 'already has 20 accounts') === 1, 'first error not repeated');
check(!str_contains($reason, 'more error'), 'Laravel summary not repeated');

// 2. A single error repeated as the message.
$reason = (new ApiException('API error: x', 422, [
    'message' => 'The max accounts field must be an integer.',
    'errors' => ['max_accounts' => ['The max accounts field must be an integer.']],
]))->getPanelReason();
check($reason === 'API error (HTTP 422): max_accounts: The max accounts field must be an integer.', 'single error once: ' . $reason);

// 3. A general message that is not one of the field errors stays in front.
$reason = (new ApiException('API error: x', 422, [
    'message' => 'The given data was invalid.',
    'errors' => ['max_accounts' => ['The reseller already has 20 accounts.']],
]))->getPanelReason();
check($reason === 'API error (HTTP 422): The given data was invalid. max_accounts: The reseller already has 20 accounts.', 'general message kept: ' . $reason);

// 4. Message only, "error" only, and no body.
check((new ApiException('API error: x', 404, ['message' => 'Reseller not found.']))->getPanelReason() === 'API error (HTTP 404): Reseller not found.', 'message only');
check((new ApiException('API error: x', 500, ['error' => 'Server Error']))->getPanelReason() === 'API error (HTTP 500): Server Error', 'error only');
check((new ApiException('API error (HTTP 502)', 502, null))->getPanelReason() === 'API error (HTTP 502)', 'no body: plain message');

tests_done();
