<?php

declare(strict_types=1);

namespace Delegirium\Http;

/**
 * HTTP-транспорт без зависимостей: curl (если расширение есть) или потоки PHP.
 *
 * Зачем два транспорта: на Windows/серверах часто выключен `allow_url_fopen`
 * или нет корректного CA-бандла — тогда спасает curl, и наоборот. Транспорт
 * выбирается автоматически (`DELEGIRIUM_HTTP=auto`), а причина сбоя сохраняется
 * дословно, чтобы её можно было показать пользователю.
 *
 * Настройки окружения:
 *   DELEGIRIUM_HTTP      auto | curl | stream
 *   DELEGIRIUM_PROXY     host:port (иначе HTTPS_PROXY/HTTP_PROXY)
 *   DELEGIRIUM_CAFILE    путь к PEM-бандлу CA
 *   DELEGIRIUM_INSECURE  1 — отключить проверку TLS (только для диагностики)
 */
final class HttpClient
{
    private const DEFAULT_UA = 'Delegirium/0.1';

    public function __construct(
        private readonly string $transport = 'auto',
        private readonly ?string $proxy = null,
        private readonly ?string $caFile = null,
        private readonly bool $insecure = false,
        private readonly string $userAgent = self::DEFAULT_UA,
    ) {
    }

    public static function fromEnv(): self
    {
        $transport = strtolower(trim((string) (getenv('DELEGIRIUM_HTTP') ?: 'auto')));

        if (!in_array($transport, ['auto', 'curl', 'stream'], true)) {
            $transport = 'auto';
        }

        return new self(
            transport: $transport,
            proxy: self::proxyFromEnv(),
            caFile: (string) (getenv('DELEGIRIUM_CAFILE') ?: '') ?: self::defaultCaFile(),
            insecure: self::truthy((string) (getenv('DELEGIRIUM_INSECURE') ?: '')),
        );
    }

    public function transportName(): string
    {
        if ($this->transport === 'curl' || ($this->transport === 'auto' && function_exists('curl_init'))) {
            return 'curl';
        }

        return 'stream';
    }

    public function proxy(): ?string
    {
        return $this->proxy;
    }

    public function caFile(): ?string
    {
        return $this->caFile;
    }

    public function insecure(): bool
    {
        return $this->insecure;
    }

    /**
     * @param list<string> $headers
     */
    public function post(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        if ($this->transport === 'curl' && !function_exists('curl_init')) {
            return new HttpResponse(0, '', 'запрошен транспорт curl, но расширение curl не загружено', 'curl');
        }

        return $this->transportName() === 'curl'
            ? $this->postCurl($url, $headers, $body, $timeout)
            : $this->postStream($url, $headers, $body, $timeout);
    }

    /**
     * Человеческие подсказки к тексту ошибки транспорта.
     *
     * @return list<string>
     */
    public static function troubleshooting(string $error): array
    {
        $error = strtolower($error);
        $hints = [];

        if (str_contains($error, 'certificate') || str_contains($error, 'ssl') || str_contains($error, 'crypto') || str_contains($error, 'verify')) {
            $hints[] = 'Похоже на проблему с TLS-сертификатом: укажите CA-бандл в DELEGIRIUM_CAFILE=/path/cacert.pem.';
            $hints[] = 'Чтобы понять, дело ли в сертификате, временно задайте DELEGIRIUM_INSECURE=1.';
        }

        if (str_contains($error, 'getaddrinfo') || str_contains($error, 'php_network_getaddresses') || str_contains($error, 'name or service') || str_contains($error, 'nodename') || str_contains($error, 'dns')) {
            $hints[] = 'Хост не разрешается в IP: проверьте DNS/VPN или задайте прокси DELEGIRIUM_PROXY=host:port.';
        }

        if (str_contains($error, 'refused') || str_contains($error, 'timed out') || str_contains($error, 'timeout') || str_contains($error, 'unreachable')) {
            $hints[] = 'Соединение не устанавливается: файрвол или прокси. Попробуйте DELEGIRIUM_PROXY=host:port.';
        }

        if (str_contains($error, 'allow_url_fopen')) {
            $hints[] = 'Включите allow_url_fopen в php.ini или используйте DELEGIRIUM_HTTP=curl.';
        }

        if (str_contains($error, 'curl не загружено')) {
            $hints[] = 'Установите php-curl или используйте DELEGIRIUM_HTTP=stream.';
        }

        return $hints;
    }

    /**
     * @param list<string> $headers
     */
    private function postCurl(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        $handle = curl_init($url);

        if ($handle === false) {
            return new HttpResponse(0, '', 'curl_init() не смог создать handle', 'curl');
        }

        $options = [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_CONNECTTIMEOUT => min($timeout, 15),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];

        if ($this->proxy !== null) {
            $options[CURLOPT_PROXY] = $this->proxy;
        }

        if ($this->caFile !== null) {
            $options[CURLOPT_CAINFO] = $this->caFile;
        }

        if ($this->insecure) {
            $options[CURLOPT_SSL_VERIFYPEER] = false;
            $options[CURLOPT_SSL_VERIFYHOST] = 0;
        }

        curl_setopt_array($handle, $options);

        /** @var string|false $raw */
        $raw = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $error = $raw === false ? (curl_error($handle) ?: 'неизвестная ошибка curl') : null;

        return new HttpResponse($status, is_string($raw) ? $raw : '', $error, 'curl');
    }

    /**
     * @param list<string> $headers
     */
    private function postStream(string $url, array $headers, string $body, int $timeout): HttpResponse
    {
        $options = [
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", [...$headers, 'User-Agent: ' . $this->userAgent, 'Connection: close']),
                'content' => $body,
                'timeout' => $timeout,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 3,
            ],
        ];

        $hints = [];

        if ($this->proxy !== null) {
            $options['http']['proxy'] = 'tcp://' . $this->proxy;
            $options['http']['request_fulluri'] = true;
        }

        if (str_starts_with(strtolower($url), 'https://')) {
            if ($this->caFile !== null) {
                $options['ssl']['cafile'] = $this->caFile;
            }

            if ($this->insecure) {
                $options['ssl']['verify_peer'] = false;
                $options['ssl']['verify_peer_name'] = false;
            }
        }

        if (!ini_get('allow_url_fopen')) {
            $hints[] = 'allow_url_fopen выключен';
        }

        $warning = null;
        set_error_handler(static function (int $severity, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });

        try {
            /** @var string|false $raw */
            $raw = file_get_contents($url, false, stream_context_create($options));
        } finally {
            restore_error_handler();
        }

        if ($raw === false) {
            $detail = trim((string) $warning);

            if ($detail === '') {
                $detail = $hints === []
                    ? 'соединение не удалось, PHP не сообщил причину'
                    : implode('; ', $hints);
            }

            return new HttpResponse(0, '', $detail, 'stream');
        }

        /** @var list<string> $responseHeaders */
        $responseHeaders = $http_response_header ?? [];

        return new HttpResponse(self::status($responseHeaders), $raw, null, 'stream');
    }

    /**
     * @param list<string> $responseHeaders
     */
    private static function status(array $responseHeaders): int
    {
        if ($responseHeaders === []) {
            return 0;
        }

        if (preg_match('~^HTTP/\S+\s+(\d{3})~', $responseHeaders[0], $matches) === 1) {
            return (int) $matches[1];
        }

        return 0;
    }

    private static function proxyFromEnv(): ?string
    {
        foreach (['DELEGIRIUM_PROXY', 'HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy'] as $name) {
            $value = getenv($name);

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return null;
    }

    /**
     * Ищет CA-бандл, если пользователь не задал DELEGIRIUM_CAFILE:
     * сначала настройки php.ini, затем типичные пути Windows и Linux.
     * Именно этого обычно не хватает на Windows (certificate verify failed).
     */
    private static function defaultCaFile(): ?string
    {
        $candidates = [];

        foreach (['openssl.cafile', 'curl.cainfo'] as $ini) {
            $value = ini_get($ini);

            if (is_string($value) && $value !== '') {
                $candidates[] = $value;
            }
        }

        $phpDir = dirname(PHP_BINARY);
        $candidates[] = $phpDir . '/extras/ssl/cacert.pem';
        $candidates[] = $phpDir . '/cacert.pem';
        $candidates[] = '/etc/ssl/certs/ca-certificates.crt';
        $candidates[] = '/etc/pki/tls/certs/ca-bundle.crt';
        $candidates[] = '/etc/ssl/cert.pem';

        foreach ($candidates as $candidate) {
            if ($candidate !== '' && is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function truthy(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }
}
