<?php

namespace OpenPix\PhpSdk;

use TypeError;
use Http\Discovery\Psr17FactoryDiscovery;
use Http\Discovery\Psr18ClientDiscovery;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

class RequestTransport
{
    public const USER_AGENT = "openpix-php-sdk";

    private $httpClient;
    private $requestFactory;
    private $streamFactory;
    private $appId;
    private $baseUri;

    public function __construct(
        string $appId,
        string $baseUri,
        ?ClientInterface $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null
    ) {
        $this->appId = $appId;
        $this->baseUri = $baseUri;
        $this->httpClient = $httpClient ?? Psr18ClientDiscovery::find();
        $this->requestFactory = $requestFactory ?? Psr17FactoryDiscovery::findRequestFactory();
        $this->streamFactory = $streamFactory ?? Psr17FactoryDiscovery::findStreamFactory();
    }

    public function transport($request): array
    {
        if (!($request instanceof RequestInterface)) {
            $request = $request->build($this->baseUri, $this->requestFactory, $this->streamFactory);
        }

        $request = $this->withRequestDefaultParameters($request);

        $response = $this->httpClient->sendRequest($request);

        return $this->hydrateResponse($response);
    }

    public function getBaseUri(): string
    {
        return $this->baseUri;
    }

    private function withRequestDefaultParameters(RequestInterface $request): RequestInterface
    {
        return $request->withAddedHeader("User-Agent", self::USER_AGENT)
            ->withAddedHeader("Authorization", $this->appId)
            ->withAddedHeader("version", Client::SDK_VERSION)
            ->withAddedHeader("platform", "openpix-php-sdk");
    }

    private function hydrateResponse(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $body = $response->getBody()->getContents();

        // =========================
        // 1. ERRO HTTP
        // =========================
        if ($status >= 400) {
            error_log("OpenPix HTTP {$status}: " . $body);
            throw new \Exception("Erro HTTP OpenPix: {$status}");
        }

        // =========================
        // 2. RESPOSTA VAZIA
        // =========================
        if (empty($body)) {
            error_log("OpenPix resposta vazia (HTTP {$status})");
            throw new \Exception("Resposta vazia da API OpenPix");
        }

        // =========================
        // 3. JSON INVÁLIDO
        // =========================
        try {
            $contents = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            error_log("OpenPix JSON inválido: " . $body);
            throw new \Exception("Resposta não é JSON válido: " . $e->getMessage());
        }

        // =========================
        // 4. FORMATO INVÁLIDO
        // =========================
        if (!is_array($contents)) {
            error_log("OpenPix resposta não é array: " . $body);
            throw new TypeError("Invalid response from API.");
        }

        // =========================
        // 5. JSON VAZIO (opcional, mas recomendado)
        // =========================
        if (empty($contents)) {
            error_log("OpenPix JSON vazio: " . $body);
            throw new \Exception("Resposta JSON vazia da API OpenPix");
        }

        // =========================
        // 6. ERRO RETORNADO PELA API
        // =========================
        if (!empty($contents["error"])) {
            $error = $contents["error"];

            if (is_array($error)) {
                $error = $error["message"] ?? json_encode($error);
            }

            error_log("OpenPix erro API: " . $error);
            throw new ApiErrorException($error);
        }

        return $contents;
    }
}
