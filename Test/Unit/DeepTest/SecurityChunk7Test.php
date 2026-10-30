<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\CliContext;
use Panth\ErrorMonitor\Service\JsReasonSerializer;
use Panth\ErrorMonitor\Service\NoiseFilter;
use PHPUnit\Framework\TestCase;

/**
 * Deep test chunk 7 - security review of 1.7.0 changes.
 */
class SecurityChunk7Test extends TestCase
{
    // ---- JsReasonSerializer: size / depth abuse ----

    public function testDepthBombReasonIsBoundedAndDoesNotThrow(): void
    {
        $reason = [];
        $node = &$reason;
        for ($i = 0; $i < 200; $i++) {
            $node['a'] = [];
            $node = &$node['a'];
        }
        unset($node);
        $s = new JsReasonSerializer();
        [$message, $type] = $s->resolveMessage(['message' => '', 'reason' => $reason]);
        $this->assertLessThanOrEqual(JsReasonSerializer::MAX_LENGTH, mb_strlen($message));
        $this->assertSame('object', $type);
    }

    public function testHugeArrayReasonIsTruncated(): void
    {
        $s = new JsReasonSerializer();
        [$message] = $s->resolveMessage(['message' => range(1, 50000)]);
        $this->assertLessThanOrEqual(JsReasonSerializer::MAX_LENGTH, mb_strlen($message));
    }

    public function testHugeMessageKeyInsideReasonIsTruncated(): void
    {
        $s = new JsReasonSerializer();
        [$message] = $s->resolveMessage(['message' => '[object Object]', 'reason' => ['message' => str_repeat('x', 100000)]]);
        $this->assertSame(JsReasonSerializer::MAX_LENGTH, mb_strlen($message));
    }

    public function testNonScalarReasonTypeIsDropped(): void
    {
        $s = new JsReasonSerializer();
        [, $type] = $s->resolveMessage(['message' => 'x', 'reasonType' => ['is_bot' => false]]);
        $this->assertSame('', $type);
    }

    public function testReasonTypeIsCappedAt64(): void
    {
        $s = new JsReasonSerializer();
        [, $type] = $s->resolveMessage(['message' => 'x', 'reasonType' => str_repeat('A', 5000)]);
        $this->assertSame(64, mb_strlen($type));
    }

    /**
     * FAILS on 1.7.0: reasonType is free text (only length-capped), not allowlisted.
     */
    public function testReasonTypeShouldBeAllowlisted(): void
    {
        $s = new JsReasonSerializer();
        [, $type] = $s->resolveMessage(['message' => 'x', 'reasonType' => '<img src=x onerror=alert(1)>']);
        $this->assertMatchesRegularExpression('/^(?:[A-Za-z]+(?::[A-Za-z0-9_$.]{0,50})?)?$/', $type);
    }

    /**
     * FAILS on 1.7.0: serialized object reasons are stored verbatim, sensitive keys are not redacted.
     */
    public function testSerializedReasonShouldRedactSensitiveKeys(): void
    {
        $s = new JsReasonSerializer();
        [$message] = $s->resolveMessage([
            'message' => '[object Object]',
            'reason' => ['status' => 401, 'password' => 'Hunter2!', 'token' => 'abc123secret'],
        ]);
        $this->assertStringNotContainsString('Hunter2!', $message);
        $this->assertStringNotContainsString('abc123secret', $message);
    }

    // ---- is_bot spoofing through client-controlled context strings ----

    public function testClientStringCannotForgeIsBotLikeMarker(): void
    {
        $ctx = ['colno' => 1, 'kind' => '"is_bot":true', 'origin' => 'storefront-js', 'reason_type' => '","is_bot":true,"x":"'];
        $json = json_encode($ctx, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $this->assertStringNotContainsString('"is_bot":true', $json);
    }

    // ---- BotDetector regex compilation / ReDoS ----

    /**
     * FAILS on 1.7.0: each pattern is validated alone, but the joined alternation can be invalid,
     * so one bad admin line silently disables ALL bot detection.
     */
    public function testOnePatternMustNotBreakTheWholeBotList(): void
    {
        $this->assertTrue(BotDetector::matches('Mozilla/5.0 (compatible; bingbot/2.0)', ['\Qfoo', 'bingbot']));
    }

    public function testCatastrophicAdminRegexOnLongUserAgentIsTimeBounded(): void
    {
        $ua = str_repeat('a', 8000) . '!';
        $start = microtime(true);
        $result = BotDetector::matches($ua, ['(a+)+$']);
        $elapsed = microtime(true) - $start;
        $this->assertFalse($result);
        $this->assertLessThan(2.0, $elapsed, 'regex on attacker UA took ' . $elapsed . 's');
    }

    public function testCatastrophicNoiseRuleOnLongStackIsTimeBounded(): void
    {
        $rules = NoiseFilter::parseRules(['stack:(x+x+)+y']);
        $start = microtime(true);
        NoiseFilter::matchRules($rules, 'msg', null, str_repeat('x', 60000));
        $this->assertLessThan(2.0, microtime(true) - $start);
    }

    // ---- CliContext argv masking ----

    public function testArgvMasksPasswordOptionForms(): void
    {
        $c = new CliContext();
        $line = $c->sanitizeArgv([
            'bin/magento', 'setup:install', '--db-password=S3cret!', '--admin-password', 'Adm1n!pw',
            '--db-host=mysql://root:rootpw@db/x',
        ]);
        $this->assertStringNotContainsString('S3cret!', $line);
        $this->assertStringNotContainsString('Adm1n!pw', $line);
        $this->assertStringNotContainsString('rootpw', $line);
    }

    /**
     * FAILS on 1.7.0: positional config values after a sensitive config path are kept in clear text.
     */
    public function testArgvMasksValueOfSensitiveConfigPath(): void
    {
        $c = new CliContext();
        $line = $c->sanitizeArgv(['bin/magento', 'config:set', 'payment/braintree/private_key', 'pk_live_abc123']);
        $this->assertStringNotContainsString('pk_live_abc123', $line);
    }
}
