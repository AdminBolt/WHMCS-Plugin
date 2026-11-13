<?php

namespace ModulesGarden\AdminBolt\Api;

use Exception;

/**
 * An error answer of the panel API. The message is the same as before this
 * class existed; the decoded response body is kept so a caller can show the
 * panel's own reason (for example the 422 validation errors).
 */
class ApiException extends Exception
{
    public function __construct(
        string $message,
        protected int $httpCode = 0,
        protected ?array $responseBody = null
    ) {
        parent::__construct($message);
    }

    public function getHttpCode(): int
    {
        return $this->httpCode;
    }

    public function getResponseBody(): ?array
    {
        return $this->responseBody;
    }

    /**
     * The panel's reason: the "message" field followed by every validation
     * error, or the plain message when the body carries neither.
     */
    public function getPanelReason(): string
    {
        $body = $this->responseBody ?? [];
        $parts = [];

        foreach(['message', 'error'] as $key)
        {
            if(!empty($body[$key]) && is_string($body[$key]))
            {
                $parts[] = $body[$key];
                break;
            }
        }

        if(!empty($body['errors']) && is_array($body['errors']))
        {
            foreach($body['errors'] as $field => $messages)
            {
                foreach((array) $messages as $message)
                {
                    if(is_string($message) && !in_array($message, $parts, true))
                    {
                        $parts[] = $field . ': ' . $message;
                    }
                }
            }
        }

        if(empty($parts))
        {
            return $this->getMessage();
        }

        return 'API error (HTTP ' . $this->httpCode . '): ' . implode(' ', $parts);
    }
}
