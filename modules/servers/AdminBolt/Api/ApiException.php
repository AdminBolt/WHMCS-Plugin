<?php

namespace ModulesGarden\AdminBolt\Api;

use Exception;

/**
 * An error answer of the panel API. The message is the short text shown to
 * WHMCS ("API error: <panel message>"); the decoded response body is kept so
 * a caller can show the panel's full reason (for example the 422 validation
 * errors).
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
     * error once, or the plain message when the body carries neither. The
     * message is left out when it only repeats a field error, including the
     * Laravel summary "<first error> (and N more errors)".
     */
    public function getPanelReason(): string
    {
        $body = $this->responseBody ?? [];
        $fieldErrors = [];
        $errorTexts = [];

        if(!empty($body['errors']) && is_array($body['errors']))
        {
            foreach($body['errors'] as $field => $messages)
            {
                foreach((array) $messages as $message)
                {
                    if(!is_string($message) || $message === '')
                    {
                        continue;
                    }

                    $errorTexts[] = $message;
                    $line = $field . ': ' . $message;

                    if(!in_array($line, $fieldErrors, true))
                    {
                        $fieldErrors[] = $line;
                    }
                }
            }
        }

        $parts = [];

        foreach(['message', 'error'] as $key)
        {
            if(!empty($body[$key]) && is_string($body[$key]))
            {
                $summary = preg_replace('/\s*\(and \d+ more errors?\)$/', '', $body[$key]);

                if(!in_array($summary, $errorTexts, true))
                {
                    $parts[] = $body[$key];
                }
                break;
            }
        }

        $parts = array_merge($parts, $fieldErrors);

        if(empty($parts))
        {
            return $this->getMessage();
        }

        return 'API error (HTTP ' . $this->httpCode . '): ' . implode(' ', $parts);
    }
}
