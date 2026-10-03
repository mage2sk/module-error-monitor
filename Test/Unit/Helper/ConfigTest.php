<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Helper;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Helper\Context;
use Panth\ErrorMonitor\Helper\Config;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private function config(array $values = [], array $flags = []): Config
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(
            static fn (string $path) => $values[$path] ?? null
        );
        $scope->method('isSetFlag')->willReturnCallback(
            static fn (string $path) => (bool)($flags[$path] ?? false)
        );
        $context = $this->createStub(Context::class);
        $context->method('getScopeConfig')->willReturn($scope);
        return new Config($context);
    }

    public function testEnabledFlagsRequireMasterSwitch(): void
    {
        $sub = [
            'panth_errormonitor/php_capture/enabled' => true,
            'panth_errormonitor/js_capture/enabled' => true,
            'panth_errormonitor/email/enabled' => true,
        ];
        $off = $this->config([], $sub);
        $this->assertFalse($off->isEnabled());
        $this->assertFalse($off->isPhpCaptureEnabled());
        $this->assertFalse($off->isJsCaptureEnabled());
        $this->assertFalse($off->isEmailEnabled());

        $on = $this->config([], $sub + ['panth_errormonitor/general/enabled' => true]);
        $this->assertTrue($on->isEnabled());
        $this->assertTrue($on->isPhpCaptureEnabled());
        $this->assertTrue($on->isJsCaptureEnabled());
        $this->assertTrue($on->isEmailEnabled());

        $masterOnly = $this->config([], ['panth_errormonitor/general/enabled' => true]);
        $this->assertFalse($masterOnly->isPhpCaptureEnabled());
        $this->assertFalse($masterOnly->isJsCaptureEnabled());
        $this->assertFalse($masterOnly->isEmailEnabled());
    }

    public function testDefaultsWhenNothingConfigured(): void
    {
        $config = $this->config();
        $this->assertSame('error', $config->getPhpMinSeverity());
        $this->assertSame(60, $config->getPhpThrottleWindowSeconds());
        $this->assertSame(0, $config->getJsSampleRate());
        $this->assertSame(30, $config->getJsRateLimitPerMinute());
        $this->assertSame(16384, $config->getJsMaxBodyBytes());
        $this->assertSame([], $config->getIgnorePatterns());
        $this->assertSame('daily_summary', $config->getEmailMode());
        $this->assertSame(0, $config->getEmailSendHour());
        $this->assertSame([], $config->getEmailRecipients());
        $this->assertSame('general', $config->getEmailSender());
        $this->assertSame('error', $config->getEmailMinSeverity());
        $this->assertSame(50, $config->getEmailMaxPerRun());
        $this->assertFalse($config->shouldStoreIp());
        $this->assertFalse($config->shouldAnonymizeIp());
        $this->assertSame(30, $config->getEventRetentionDays());
        $this->assertSame(90, $config->getResolvedGroupRetentionDays());
        $this->assertSame(0, $config->getUnresolvedGroupRetentionDays());
    }

    public function testConfiguredValuesAreReturned(): void
    {
        $config = $this->config([
            'panth_errormonitor/php_capture/min_severity' => 'warning',
            'panth_errormonitor/php_capture/throttle_window_seconds' => '120',
            'panth_errormonitor/js_capture/sample_rate' => '25',
            'panth_errormonitor/js_capture/rate_limit_per_minute' => '5',
            'panth_errormonitor/js_capture/max_body_kb' => '4',
            'panth_errormonitor/email/mode' => 'immediate_digest',
            'panth_errormonitor/email/send_hour' => '7',
            'panth_errormonitor/email/sender' => 'support',
            'panth_errormonitor/email/min_severity' => 'critical',
            'panth_errormonitor/email/max_per_run' => '10',
            'panth_errormonitor/retention/event_days' => '7',
            'panth_errormonitor/retention/resolved_group_days' => '14',
            'panth_errormonitor/retention/unresolved_group_days' => '60',
        ], [
            'panth_errormonitor/privacy/store_ip' => true,
            'panth_errormonitor/privacy/anonymize_ip' => true,
        ]);
        $this->assertSame('warning', $config->getPhpMinSeverity());
        $this->assertSame(120, $config->getPhpThrottleWindowSeconds());
        $this->assertSame(25, $config->getJsSampleRate());
        $this->assertSame(5, $config->getJsRateLimitPerMinute());
        $this->assertSame(4096, $config->getJsMaxBodyBytes());
        $this->assertSame('immediate_digest', $config->getEmailMode());
        $this->assertSame(7, $config->getEmailSendHour());
        $this->assertSame('support', $config->getEmailSender());
        $this->assertSame('critical', $config->getEmailMinSeverity());
        $this->assertSame(10, $config->getEmailMaxPerRun());
        $this->assertTrue($config->shouldStoreIp());
        $this->assertTrue($config->shouldAnonymizeIp());
        $this->assertSame(7, $config->getEventRetentionDays());
        $this->assertSame(14, $config->getResolvedGroupRetentionDays());
        $this->assertSame(60, $config->getUnresolvedGroupRetentionDays());
    }

    public static function throttleProvider(): array
    {
        return [
            'empty string' => ['', 60],
            'zero' => ['0', 0],
            'negative clamps to zero' => ['-30', 0],
            'positive' => ['15', 15],
        ];
    }

    #[DataProvider('throttleProvider')]
    public function testThrottleWindow(string $raw, int $expected): void
    {
        $config = $this->config(['panth_errormonitor/php_capture/throttle_window_seconds' => $raw]);
        $this->assertSame($expected, $config->getPhpThrottleWindowSeconds());
    }

    public static function clampProvider(): array
    {
        return [
            'rate negative' => ['js_capture/sample_rate', '-5', 'getJsSampleRate', 0],
            'rate above max' => ['js_capture/sample_rate', '150', 'getJsSampleRate', 100],
            'rate exact max' => ['js_capture/sample_rate', '100', 'getJsSampleRate', 100],
            'hour negative' => ['email/send_hour', '-1', 'getEmailSendHour', 0],
            'hour above max' => ['email/send_hour', '30', 'getEmailSendHour', 23],
            'hour max' => ['email/send_hour', '23', 'getEmailSendHour', 23],
            'rate limit zero' => ['js_capture/rate_limit_per_minute', '0', 'getJsRateLimitPerMinute', 30],
            'rate limit negative' => ['js_capture/rate_limit_per_minute', '-3', 'getJsRateLimitPerMinute', 30],
            'body negative' => ['js_capture/max_body_kb', '-1', 'getJsMaxBodyBytes', 16384],
            'max per run negative' => ['email/max_per_run', '-1', 'getEmailMaxPerRun', 50],
            'event days zero' => ['retention/event_days', '0', 'getEventRetentionDays', 30],
            'resolved days negative' => ['retention/resolved_group_days', '-2', 'getResolvedGroupRetentionDays', 90],
            'unresolved negative' => ['retention/unresolved_group_days', '-2', 'getUnresolvedGroupRetentionDays', 0],
        ];
    }

    #[DataProvider('clampProvider')]
    public function testNumericValuesAreClamped(string $path, string $raw, string $method, int $expected): void
    {
        $config = $this->config(['panth_errormonitor/' . $path => $raw]);
        $this->assertSame($expected, $config->{$method}());
    }

    public function testIgnorePatternsPreferGeneralAndNormalise(): void
    {
        $config = $this->config([
            'panth_errormonitor/general/ignore_patterns' => "  Foo Bar \r\n\r\nSCRIPT error\rlast",
            'panth_errormonitor/js_capture/ignore_patterns' => 'legacy',
        ]);
        $this->assertSame(['foo bar', 'script error', 'last'], $config->getIgnorePatterns());
    }

    public function testIgnorePatternsFallBackToLegacyPath(): void
    {
        $config = $this->config([
            'panth_errormonitor/js_capture/ignore_patterns' => "Legacy One\nLegacy Two",
        ]);
        $this->assertSame(['legacy one', 'legacy two'], $config->getIgnorePatterns());
    }

    public function testIgnorePatternsWithOnlyBlankLinesIsEmpty(): void
    {
        $config = $this->config(['panth_errormonitor/general/ignore_patterns' => "  \n \n"]);
        $this->assertSame([], $config->getIgnorePatterns());
    }

    public function testRecipientsAreValidatedAndDeduplicated(): void
    {
        $config = $this->config([
            'panth_errormonitor/email/recipients' => "a@example.com, not-an-email\nb@example.com,,a@example.com\r\n c@example.org ",
        ]);
        $this->assertSame(['a@example.com', 'b@example.com', 'c@example.org'], $config->getEmailRecipients());
    }

    public function testRecipientsAllInvalidReturnsEmpty(): void
    {
        $config = $this->config(['panth_errormonitor/email/recipients' => 'nope, also nope']);
        $this->assertSame([], $config->getEmailRecipients());
    }
}
