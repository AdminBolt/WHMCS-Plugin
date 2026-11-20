<?php

namespace ModulesGarden\AdminBolt\Api;

use Exception;
use ModulesGarden\AdminBolt\Helpers\ModuleLog;

class AdminBolt
{
    /** Seconds to wait for the panel to accept the connection. */
    public const CONNECT_TIMEOUT_SECONDS = 15;

    /**
     * Seconds a call may take in all, below the usual WHMCS PHP time limit,
     * so a panel that hangs fails the command with a clear error instead of
     * holding it until WHMCS kills it. withTimeout() changes it for one call.
     */
    public const TIMEOUT_SECONDS = 240;

    protected int $timeoutSeconds = self::TIMEOUT_SECONDS;

    public function __construct(
        protected string $url,
        protected string $apiKey,
        protected string $apiSecret
    ) {}

    protected function request(string $method, string $endpoint, ?array $content = null): ?array
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->url . $endpoint);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_HEADER, true);
        curl_setopt($curl, CURLINFO_HEADER_OUT, true);
        curl_setopt($curl, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SECONDS);
        curl_setopt($curl, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-API-Key: ' . $this->apiKey,
            'X-API-Secret: ' . $this->apiSecret,
        ]);

        // IGNORE CERT
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        // IGNORE CERT

        if($content)
        {
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($content));
        }

        $response = curl_exec($curl);

        if(curl_errno($curl))
        {
            throw new Exception('API error: ' . curl_error($curl));
        }

        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

        $requestHeaders = curl_getinfo($curl, CURLINFO_HEADER_OUT);
        $request = $requestHeaders . ($content ? json_encode($content, JSON_PRETTY_PRINT) : '');

        $responseHeaderSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        $responseBody = substr($response, $responseHeaderSize);

        $this->logCall($endpoint, $request, (string) $response, $content);

        curl_close($curl);

        $responseBodyDecoded = json_decode($responseBody, true);

        if(!self::isSuccessCode((int) $httpCode))
        {
            // The panel reports errors under "message"; "error" is kept for older responses.
            $errorMessage = 'API error (HTTP ' . $httpCode . ')';

            foreach(['error', 'message'] as $key)
            {
                if(is_array($responseBodyDecoded) && isset($responseBodyDecoded[$key]) && is_string($responseBodyDecoded[$key]) && $responseBodyDecoded[$key] !== '')
                {
                    $errorMessage = 'API error: ' . $responseBodyDecoded[$key];
                    break;
                }
            }

            throw new ApiException(
                $errorMessage,
                (int) $httpCode,
                is_array($responseBodyDecoded) ? $responseBodyDecoded : null
            );
        }

        return $responseBodyDecoded;
    }

    /**
     * The HTTP codes of a panel answer that is not an error: 202 is a call
     * the panel accepted and does in the background (reseller termination).
     */
    public static function isSuccessCode(int $httpCode): bool
    {
        return in_array($httpCode, [200, 201, 202, 204], true);
    }

    /**
     * Module Log entry of an API call, without the API key, the API secret
     * and passwords sent in the body.
     */
    protected function logCall(string $endpoint, string $request, string $response, ?array $content): void
    {
        ModuleLog::call(
            $endpoint,
            $request,
            $response,
            $response,
            array_merge([$this->apiKey, $this->apiSecret], ModuleLog::secretsOf($content ?? []))
        );
    }

    /** A copy of this client whose calls time out after $seconds in all. */
    public function withTimeout(int $seconds): static
    {
        $client = clone $this;
        $client->timeoutSeconds = $seconds;

        return $client;
    }

    public function timeoutSeconds(): int
    {
        return $this->timeoutSeconds;
    }

    public function get(string $endpoint): ?array
    {
        return $this->request('GET', $endpoint);
    }

    public function post(string $endpoint, ?array $content = null): ?array
    {
        return $this->request('POST', $endpoint, $content);
    }

    public function put(string $endpoint, ?array $content = null): ?array
    {
        return $this->request('PUT', $endpoint, $content);
    }

    public function delete(string $endpoint): ?array
    {
        return $this->request('DELETE', $endpoint);
    }
}