<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * The widget script as a pinned, content-addressed URL plus its Subresource
 * Integrity hash, for stores that turn on "Load With Subresource Integrity".
 *
 * The rolling `<widget_url>/widget/v1/<api_key>.js` changes with every IDEA89
 * release and every dashboard save, so it can never carry an `integrity`
 * attribute. `<api_url>/widget/v1/<api_key>/pin.json` returns a URL whose path
 * is the SHA-384 of the bytes it serves, and the `sha384-…` value for the tag:
 * those bytes can never change, so the browser check always holds.
 *
 * Caching: the answer is kept in Magento's cache for the `max_age` the API
 * sends (clamped to 1–60 minutes); a failure is remembered for one minute so
 * an unreachable API costs one 2-second timeout a minute, not one per page.
 *
 * Failure mode: any error, or an answer that does not look exactly like a pin
 * on the configured widget host, gives null and the block renders the rolling
 * URL without `integrity`, as before. A pin must never make the widget vanish.
 */
class WidgetPin
{
    public const CACHE_TAG = 'IDEA89_WIDGET_PIN';

    private const FAILURE_TTL = 60;
    private const MIN_TTL = 60;
    private const MAX_TTL = 3600;

    /** @var array{src: string, integrity: string}|false|null request-scoped memo; false = no pin */
    private array|false|null $memo = null;

    public function __construct(
        private readonly Config $config,
        private readonly Curl $curl,
        private readonly CacheInterface $cache,
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @return array{src: string, integrity: string}|null
     */
    public function get(): ?array
    {
        if ($this->memo !== null) {
            return $this->memo ?: null;
        }

        $apiKey = $this->config->getApiKey();
        if (!$this->config->isWidgetIntegrityEnabled() || $apiKey === '') {
            $this->memo = false;
            return null;
        }

        $cacheKey = 'idea89_widget_pin_' . hash('sha256', $apiKey . '|' . $this->config->getWidgetUrl());
        $cached = $this->cache->load($cacheKey);
        if (is_string($cached) && $cached !== '') {
            $decoded = $this->serializer->unserialize($cached);
            if (is_array($decoded)) {
                $pin = $this->validate($decoded);
                $this->memo = $pin ?? false;
                return $pin;
            }
        }

        [$pin, $ttl] = $this->fetch($apiKey);
        $this->cache->save(
            $this->serializer->serialize($pin ?? ['none' => true]),
            $cacheKey,
            [self::CACHE_TAG],
            $pin === null ? self::FAILURE_TTL : $ttl
        );

        $this->memo = $pin ?? false;
        return $pin;
    }

    /**
     * @return array{0: array{src: string, integrity: string}|null, 1: int}
     */
    private function fetch(string $apiKey): array
    {
        $apiUrl = rtrim($this->config->getApiUrl(), '/');
        if ($apiUrl === '') {
            return [null, self::FAILURE_TTL];
        }

        try {
            $this->curl->setTimeout(2);
            $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 1);
            $this->curl->get($apiUrl . '/widget/v1/' . rawurlencode($apiKey) . '/pin.json');
            if ($this->curl->getStatus() !== 200) {
                return [null, self::FAILURE_TTL];
            }
            $body = $this->serializer->unserialize($this->curl->getBody());
        } catch (\Throwable $e) {
            return [null, self::FAILURE_TTL];
        }

        if (!is_array($body)) {
            return [null, self::FAILURE_TTL];
        }

        $maxAge = is_int($body['max_age'] ?? null) ? $body['max_age'] : self::MIN_TTL;

        return [$this->validate($body), max(self::MIN_TTL, min(self::MAX_TTL, $maxAge))];
    }

    /**
     * Only a URL on the configured widget host, under /widget/v1/p/, named by a
     * 96-character sha384 hex digest, with a matching-shape sha384 integrity
     * value, is ever rendered. Anything else is treated as no pin.
     *
     * @param array<mixed> $data
     * @return array{src: string, integrity: string}|null
     */
    private function validate(array $data): ?array
    {
        $src = $data['src'] ?? null;
        $integrity = $data['integrity'] ?? null;
        if (!is_string($src) || !is_string($integrity)) {
            return null;
        }

        $prefix = rtrim($this->config->getWidgetUrl(), '/') . '/widget/v1/p/';
        if (!str_starts_with($src, $prefix)) {
            return null;
        }
        if (preg_match('#^[a-f0-9]{96}\.js$#', substr($src, strlen($prefix))) !== 1) {
            return null;
        }
        if (preg_match('#^sha384-[A-Za-z0-9+/]{64}$#', $integrity) !== 1) {
            return null;
        }

        return ['src' => $src, 'integrity' => $integrity];
    }
}
