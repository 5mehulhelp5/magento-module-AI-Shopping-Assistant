<?php
/**
 * Copyright © 4K Technologies Ltd. All rights reserved.
 * See LICENSE file for license details.
 */

declare(strict_types=1);

namespace Idea89\Assistant\Test\Unit\Model;

use Idea89\Assistant\Model\Config;
use Idea89\Assistant\Model\WidgetPin;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class WidgetPinTest extends TestCase
{
    private const HASH = 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12'
        . 'ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12ab12';
    private const INTEGRITY = 'sha384-qxKrEqsSqxKrEqsSqxKrEqsSqxKrEqsSqxKrEqsSqxKrEqsSqxKrEqsSqxKrEqsS';
    private const PINNED = 'https://api.idea89.com/widget/v1/p/' . self::HASH . '.js';

    /** @var array<string, array{0: string, 1: int}> */
    private array $store = [];
    private Curl&MockObject $curl;

    private function pin(
        bool $enabled = true,
        string $apiKey = 'pk_live_1',
        string $widgetUrl = 'https://api.idea89.com'
    ): WidgetPin {
        $config = $this->createMock(Config::class);
        $config->method('isWidgetIntegrityEnabled')->willReturn($enabled);
        $config->method('getApiKey')->willReturn($apiKey);
        $config->method('getApiUrl')->willReturn('https://api.idea89.com');
        $config->method('getWidgetUrl')->willReturn($widgetUrl);

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(fn (string $k) => $this->store[$k][0] ?? false);
        $cache->method('save')->willReturnCallback(function (string $data, string $k, array $tags, ?int $ttl) {
            $this->store[$k] = [$data, (int) $ttl];
            return true;
        });

        return new WidgetPin($config, $this->curl, $cache, new Json());
    }

    private function answer(int $status, string $body): void
    {
        $this->curl->method('getStatus')->willReturn($status);
        $this->curl->method('getBody')->willReturn($body);
    }

    protected function setUp(): void
    {
        $this->store = [];
        $this->curl = $this->createMock(Curl::class);
    }

    public function testAValidPinIsReturnedAndCachedForTheMaxAgeTheApiSends(): void
    {
        $this->curl->expects(self::once())->method('get')
            ->with('https://api.idea89.com/widget/v1/pk_live_1/pin.json');
        $this->answer(200, json_encode([
            'src' => self::PINNED,
            'integrity' => self::INTEGRITY,
            'max_age' => 600,
        ]));

        $pin = $this->pin()->get();

        self::assertSame(['src' => self::PINNED, 'integrity' => self::INTEGRITY], $pin);
        self::assertSame(600, array_values($this->store)[0][1]);
    }

    public function testASecondRequestIsServedFromTheCacheWithoutCallingTheApi(): void
    {
        $this->answer(200, json_encode([
            'src' => self::PINNED,
            'integrity' => self::INTEGRITY,
            'max_age' => 600,
        ]));
        $this->curl->expects(self::once())->method('get');

        $first = $this->pin()->get();
        $second = $this->pin()->get(); // a new request: new instance, same cache

        self::assertSame($first, $second);
    }

    public function testTurnedOffMeansNoPinAndNoCall(): void
    {
        $this->curl->expects(self::never())->method('get');
        self::assertNull($this->pin(enabled: false)->get());
    }

    public function testAnUnreachableApiGivesNoPinAndIsRememberedForAMinute(): void
    {
        $this->curl->method('get')->willThrowException(new \Exception('timeout'));

        self::assertNull($this->pin()->get());
        self::assertSame(60, array_values($this->store)[0][1]);
    }

    public function testANon200AnswerGivesNoPin(): void
    {
        $this->answer(404, '{"error":"widget_unavailable"}');
        self::assertNull($this->pin()->get());
    }

    /**
     * @param array<string, mixed> $answer
     */
    #[DataProvider('untrustworthyAnswers')]
    public function testAnAnswerThatIsNotExactlyAPinOnTheWidgetHostIsNeverRendered(array $answer): void
    {
        $this->answer(200, json_encode($answer + ['max_age' => 600]));
        self::assertNull($this->pin()->get());
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function untrustworthyAnswers(): array
    {
        $src = self::PINNED;
        $host = 'https://api.idea89.com';
        return [
            'another host' => [['src' => 'https://evil.example/widget/v1/p/' . self::HASH . '.js',
                'integrity' => self::INTEGRITY]],
            'look-alike host' => [['src' => $host . '.evil.example/widget/v1/p/' . self::HASH . '.js',
                'integrity' => self::INTEGRITY]],
            'not a pin path' => [['src' => $host . '/widget/v1/pk_live_1.js', 'integrity' => self::INTEGRITY]],
            'short hash' => [['src' => $host . '/widget/v1/p/abc.js', 'integrity' => self::INTEGRITY]],
            'path tricks' => [['src' => $src . '?x=<script>', 'integrity' => self::INTEGRITY]],
            'sha256 integrity' => [['src' => $src, 'integrity' => 'sha256-' . str_repeat('A', 43) . '=']],
            'quote in integrity' => [['src' => $src, 'integrity' => 'sha384-"' . str_repeat('A', 63)]],
            'missing integrity' => [['src' => $src]],
        ];
    }
}
