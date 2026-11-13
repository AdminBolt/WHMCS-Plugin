<?php

namespace ModulesGarden\AdminBolt\Api;

use Exception;

class AdminBolt
{
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

        \logModuleCall('AdminBolt', $endpoint, $request, $response, $response);

        curl_close($curl);

        $responseBodyDecoded = json_decode($responseBody, true);

        if($httpCode != 200 && $httpCode != 201 && $httpCode != 204)
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