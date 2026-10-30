<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\FlagManager;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Template\Context;
use Panth\ErrorMonitor\Block\Email\Summary;
use Panth\ErrorMonitor\Controller\Adminhtml\Error\View as ViewController;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup\CollectionFactory;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Model\Summary\DigestHolder;
use Panth\ErrorMonitor\Model\System\Message\RefingerprintPending;
use Panth\ErrorMonitor\Service\BotDetector;
use Panth\ErrorMonitor\Service\CaptureThrottle;
use Panth\ErrorMonitor\Service\CliContext;
use Panth\ErrorMonitor\Service\DeploymentGuard;
use Panth\ErrorMonitor\Service\ErrorPayload;
use Panth\ErrorMonitor\Service\ErrorRecorder;
use Panth\ErrorMonitor\Service\Fingerprinter;
use Panth\ErrorMonitor\Service\GraphQlClientErrorFilter;
use Panth\ErrorMonitor\Service\IpAnonymizer;
use Panth\ErrorMonitor\Service\NewGroupLimiter;
use Panth\ErrorMonitor\Service\NoiseFilter;
use Panth\ErrorMonitor\Service\PathMapper;
use Panth\ErrorMonitor\Service\RateLimiter;
use Panth\ErrorMonitor\Service\TimeFormatter;
use Panth\ErrorMonitor\Test\Unit\MonologRecordTrait;
use PHPUnit\Framework\TestCase;

class ReleaseFixesTest extends TestCase
{
    use MonologRecordTrait;

    private array $server = [];

    private array $payloads = [];

    private array $fetchQueue = [];

    private array $where = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        foreach (['REQUEST_URI', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'REQUEST_METHOD'] as $key) {
            unset($_SERVER[$key]);
        }
        GraphQlClientErrorFilter::reset();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        GraphQlClientErrorFilter::reset();
        (new DigestHolder())->clear();
    }

    public function testRateLimiterUsesAnAtomicDatabaseCounter(): void
    {
        $counters = [];
        $last = 0;
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . $v . '`');
        $conn->method('query')->willReturnCallback(function ($sql, $bind) use (&$counters, &$last) {
            $this->assertStringContainsString('ON DUPLICATE KEY UPDATE hits = LAST_INSERT_ID(hits + 1)', $sql);
            $counters[$bind[0]] = ($counters[$bind[0]] ?? 0) + 1;
            $last = $counters[$bind[0]];
            return null;
        });
        $conn->method('fetchOne')->willReturnCallback(static function () use (&$last) {
            return (string)$last;
        });
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $resource->method('getTableName')->willReturnArgument(0);
        $cache = $this->createMock(CacheInterface::class);
        $cache->expects($this->never())->method('save');

        $limiter = new RateLimiter($cache, $resource);
        $this->assertTrue($limiter->allow('10.0.0.1', 2));
        $this->assertTrue($limiter->allow('10.0.0.1', 2));
        $this->assertFalse($limiter->allow('10.0.0.1', 2));
        $this->assertTrue($limiter->allow('10.0.0.2', 2));
    }

    public function testRateLimiterFallsBackToTheCacheWhenTheTableIsMissing(): void
    {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('query')->willThrowException(new \RuntimeException('Base table not found'));
        $resource = $this->createStub(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($conn);
        $store = [];
        $cache = $this->createStub(CacheInterface::class);
        $cache->method('load')->willReturnCallback(static function ($id) use (&$store) {
            return $store[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(static function ($data, $id) use (&$store) {
            $store[$id] = $data;
            return true;
        });
        $limiter = new RateLimiter($cache, $resource);
        $this->assertTrue($limiter->allow('1.1.1.1', 1));
        $this->assertFalse($limiter->allow('1.1.1.1', 1));
    }

    public function testArgvMaskingCoversConfigCommandsAndPii(): void
    {
        $c = new CliContext();
        $this->assertSame(
            'bin/magento config:set --scope=stores web/secure/base_url https://shop.test/',
            $c->sanitizeArgv(['bin/magento', 'config:set', '--scope=stores', 'web/secure/base_url', 'https://shop.test/'])
        );
        $this->assertSame(
            'bin/magento config:set payment/x/api_token ***',
            $c->sanitizeArgv(['bin/magento', 'config:set', 'payment/x/api_token', 'tok_1'])
        );
        $this->assertSame(
            'bin/magento config:sensitive:set carriers/ups/username ***',
            $c->sanitizeArgv(['bin/magento', 'config:sensitive:set', 'carriers/ups/username', 'bob'])
        );
        $this->assertSame('mysql -uroot -p***', $c->sanitizeArgv(['mysql', '-uroot', '-phunter2']));
        $this->assertSame(
            'bin/magento admin:user:create --admin-firstname *** --admin-lastname=***',
            $c->sanitizeArgv(['bin/magento', 'admin:user:create', '--admin-firstname', 'Bob', '--admin-lastname=Smith'])
        );
        $this->assertSame(
            'bin/magento queue:consumers:start async.operations.all --max-messages=100',
            $c->sanitizeArgv(['bin/magento', 'queue:consumers:start', 'async.operations.all', '--max-messages=100'])
        );
    }

    public function testBotWordMatchingAvoidsDeviceAndAppNames(): void
    {
        foreach (['Twitterbot/1.0', 'Mozilla/5.0 (compatible; Applebot/0.1)', 'Slackbot-LinkExpanding 1.0', 'bot'] as $ua) {
            $this->assertTrue(BotDetector::matches($ua, ['bot']), $ua);
        }
        foreach (['CUBOT_NOTE_20', 'Mozilla/5.0 RobotVacuumApp/2.1', 'AbbottLibreLink/2.10', 'CUBOT KINGKONG'] as $ua) {
            $this->assertFalse(BotDetector::matches($ua, ['bot']), $ua);
        }
        $this->assertTrue(BotDetector::matches('x', ['\Qfoo', '(', 'x']));
        $this->assertTrue(BotDetector::matches('barbar', ['(foo)x', '(bar)\1']));
    }

    public function testNoiseRuleWithAnInvalidPatternNeverThrows(): void
    {
        $rules = NoiseFilter::parseRules(['source:\Qcustomer-data && message:boom', '[', 'file:(?<n>x)\k<n>']);
        $this->assertCount(2, $rules);
        $this->assertTrue(NoiseFilter::matchRules($rules, 'boom', 'customer-data.js'));
        $this->assertFalse(NoiseFilter::matchRules($rules, 'boom', 'other.js'));
        $this->assertTrue(NoiseFilter::matchRules($rules, 'x', 'xx'));
    }

    public function testScriptErrorRuleUsesStoredStackAvailability(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static fn ($p) => $p === NoiseFilter::XML_ENABLED);
        $scope->method('getValue')->willReturn(null);
        $filter = new NoiseFilter($scope);
        $this->assertTrue($filter->isIgnored('Script error.', null, 'Error', null, false));
        $this->assertFalse($filter->isIgnored('Script error.', null, 'Error', null, true));
        $this->assertTrue($filter->isIgnored('Script error.', null, 'Error', null, null));
    }

    public function testReportIdWrapperMessageIsMatchedAgainstRegisteredClientErrors(): void
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $filter = new GraphQlClientErrorFilter($scope);
        $filter->register([new GraphQlInputException(new Phrase('The region_id does not match the selected country or region'))]);
        $this->assertTrue($filter->isClientSafe(
            'Report ID: graph-ql-6720f1a3b9c4d; Message: The region_id does not match the selected country or region'
        ));
        $wrapper = new \Exception('Report ID: graph-ql-6720f1a3b9c4d; Message: boom', 0, new \RuntimeException('boom'));
        $this->assertFalse($filter->isClientSafeThrowable($wrapper));
    }

    private function handler(): DbHandler
    {
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $payload): ?int {
            $this->payloads[] = $payload;
            return 1;
        });
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static fn ($path) => in_array($path, [
            'panth_errormonitor/general/enabled',
            'panth_errormonitor/php_capture/enabled',
        ], true));
        $scope->method('getValue')->willReturn(null);
        $ip = $this->createStub(IpAnonymizer::class);
        $ip->method('process')->willReturnArgument(0);
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturn(false);
        return new DbHandler($recorder, $scope, $ip, $guard, new Fingerprinter());
    }

    public function testConsoleUsageErrorsAreNotRecorded(): void
    {
        $message = "Command \"cache:flsh\" is not defined.\n"
            . "#0 /var/www/html/vendor/symfony/console/Application.php(280): Symfony\\Component\\Console\\Application->find('cache:flsh')\n"
            . '#1 /var/www/html/vendor/magento/framework/Console/Cli.php(137): Symfony\\Component\\Console\\Application->doRun()';
        $this->handler()->handle($this->makeRecord('error', $message));
        $this->assertSame([], $this->payloads);

        $real = "Something failed\n"
            . "#0 /var/www/html/vendor/symfony/console/Command/Command.php(326): Vendor\\Module\\Console\\Command\\Run->execute()\n"
            . '#1 {main}';
        $this->handler()->handle($this->makeRecord('error', $real));
        $this->assertCount(1, $this->payloads);

        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->handler()->handle($this->makeRecord('error', $message));
        $this->assertCount(2, $this->payloads, 'web requests are never treated as console usage errors');
    }

    public function testOnlyConsoleUsageExceptionsAreSkippedAndCommandBugsAreRecorded(): void
    {
        $usage = [
            new \Symfony\Component\Console\Exception\CommandNotFoundException('Command "x" is not defined.'),
            new \Symfony\Component\Console\Exception\NamespaceNotFoundException('There are no commands defined in the "y" namespace.'),
            new \Symfony\Component\Console\Exception\InvalidOptionException('The "--force" option requires a value.'),
            new \Symfony\Component\Console\Exception\RuntimeException('Not enough arguments (missing: "path").'),
            new \Symfony\Component\Console\Exception\RuntimeException('Too many arguments, expected arguments "command".'),
            new \Symfony\Component\Console\Exception\RuntimeException('The "--nope" option does not exist.'),
            new \Symfony\Component\Console\Exception\InvalidArgumentException('The "-z" option does not exist.'),
        ];
        foreach ($usage as $e) {
            $this->handler()->handle($this->makeRecord('error', $e->getMessage(), ['exception' => $e]));
        }
        $this->assertSame([], $this->payloads);

        $bugs = [
            new \LogicException('Cannot rebuild index: lock held'),
            new \Symfony\Component\Console\Exception\InvalidArgumentException('The "store" option does not exist.'),
            new \Symfony\Component\Console\Exception\RuntimeException('Unable to write the output file.'),
            new \Symfony\Component\Console\Exception\LogicException('An option named "x" already exists.'),
        ];
        foreach ($bugs as $e) {
            $this->handler()->handle($this->makeRecord('error', $e->getMessage(), ['exception' => $e]));
        }
        $this->assertCount(4, $this->payloads);

        $bugMessage = "Cannot rebuild index: lock held\n"
            . "#0 /var/www/html/vendor/symfony/console/Command/Command.php(326): Symfony\\Component\\Console\\Command\\Command->run()\n"
            . '#1 {main}';
        $this->handler()->handle($this->makeRecord('error', $bugMessage));
        $this->assertCount(5, $this->payloads, 'a message without a usage phrase is recorded even with a console frame');
    }

    public function testConfigSetValueIsMaskedForEveryOptionOrder(): void
    {
        $c = new CliContext();
        $cases = [
            ['config:set', '--scope', 'websites', '--scope-code', 'base', 'carriers/ups/password', 'Hunter2pw'],
            ['config:set', '--scope=websites', '--scope-code=base', 'carriers/ups/password', 'Hunter2pw'],
            ['config:set', 'carriers/ups/password', '--scope', 'websites', 'Hunter2pw'],
            ['config:set', 'carriers/ups/password', 'Hunter2pw', '--scope', 'websites', '--scope-code', 'base'],
            ['config:set', '--lock-env', 'carriers/ups/password', 'Hunter2pw'],
            ['config:set', '-e', '--scope', 'stores', 'carriers/ups/password', '-c', 'Hunter2pw'],
            ['config:set', '--lock-config', '--scope-code', 'base', '--scope', 'websites', 'carriers/ups/password', 'Hunter2pw'],
            ['config:sensitive:set', '--scope', 'websites', '--scope-code', 'base', 'payment/x/merchant_id', 'Hunter2pw'],
            ['config:sensitive:set', 'payment/x/merchant_id', '-i', 'Hunter2pw'],
        ];
        foreach ($cases as $case) {
            $line = $c->sanitizeArgv(array_merge(['bin/magento'], $case));
            $this->assertStringNotContainsString('Hunter2pw', $line, $line);
            $this->assertStringContainsString('***', $line, $line);
        }
        $this->assertSame(
            'bin/magento config:set --scope websites --scope-code base web/unsecure/base_url https://shop.test/',
            $c->sanitizeArgv(['bin/magento', 'config:set', '--scope', 'websites', '--scope-code', 'base',
                'web/unsecure/base_url', 'https://shop.test/'])
        );
    }

    public function testDigestCountsThePartialFirstHourFromEventRows(): void
    {
        $this->fetchQueue = [
            [['group_id' => 1, 'cnt' => 20, 'bot_cnt' => 0]],
            [['group_id' => 1, 'cnt' => 2, 'last_at' => '2026-10-28 07:45:00']],
            [],
            [],
            [$this->group(1)],
        ];
        $now = gmmktime(7, 30, 0, 10, 29, 2026);
        $digest = $this->builder(null, '2026-10-01 00:00:00')->build($now);
        $this->assertSame(22, $digest['rows'][0]['count_24h']);
        $this->assertContains(['hour_start >= ?', '2026-10-28 08:00:00'], $this->where);
        $this->assertContains(['created_at >= ?', '2026-10-28 07:30:00'], $this->where);
        $this->assertContains(['created_at < ?', '2026-10-28 08:00:00'], $this->where);
    }

    public function testDigestOnAnHourBoundaryUsesOnlyCounters(): void
    {
        $this->fetchQueue = [
            [['group_id' => 1, 'cnt' => 7, 'bot_cnt' => 0]],
            [],
            [$this->group(1)],
        ];
        $now = gmmktime(8, 0, 0, 10, 29, 2026);
        $digest = $this->builder(null, '2026-10-01 00:00:00')->build($now);
        $this->assertSame(7, $digest['rows'][0]['count_24h']);
        $this->assertSame([['hour_start >= ?', '2026-10-28 08:00:00']], array_values(array_filter(
            $this->where,
            static fn ($w) => in_array($w[0], ['hour_start >= ?', 'created_at < ?'], true)
        )));
    }

    public function testLongScalarContextValuesAreCapped(): void
    {
        $this->handler()->handle($this->makeRecord('error', 'x', ['body' => str_repeat('b', 5000), 'n' => 5]));
        $this->assertSame(2003, strlen($this->payloads[0]->context['body']));
        $this->assertSame(5, $this->payloads[0]->context['n']);
    }

    public function testRecorderKeepsContextJsonValidAndBelowTheColumnSize(): void
    {
        $recorder = new ErrorRecorder(
            $this->createStub(ErrorGroupResource::class),
            $this->createStub(ErrorEventResource::class),
            new Fingerprinter(),
            $this->createStub(ScopeConfigInterface::class),
            $this->createStub(CaptureThrottle::class),
            $this->createStub(NewGroupLimiter::class)
        );
        $context = [];
        for ($i = 0; $i < 200; $i++) {
            $context['k' . $i] = str_repeat('z', 1000);
        }
        $json = (string)$recorder->encodeContext($context);
        $this->assertLessThanOrEqual(ErrorRecorder::MAX_CONTEXT_BYTES, strlen($json));
        $this->assertIsArray(json_decode($json, true));

        $huge = [];
        for ($i = 0; $i < 2000; $i++) {
            $huge['key' . $i] = str_repeat('y', 100);
        }
        $decoded = json_decode((string)$recorder->encodeContext($huge), true);
        $this->assertTrue($decoded['context_truncated']);
        $this->assertCount(50, $decoded['keys']);
        $this->assertNull($recorder->encodeContext([]));
    }

    private function builder(?callable $zones = null, ?string $countersSince = null): DigestBuilder
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);
        $scope->method('getValue')->willReturn(null);

        $select = $this->createStub(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('group')->willReturnSelf();
        $select->method('where')->willReturnCallback(function ($cond, $value = null) use ($select) {
            $this->where[] = [$cond, $value];
            return $select;
        });
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $conn->method('quoteInto')->willReturnCallback(static fn ($text, $value) => str_replace('?', "'" . $value . "'", $text));
        $conn->method('fetchAll')->willReturnCallback(fn () => array_shift($this->fetchQueue) ?? []);
        $conn->method('fetchOne')->willReturn($countersSince);
        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($conn);

        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturnCallback(
            $zones ?? static fn ($type = null, $code = null) => 'UTC'
        );
        $config = $this->createStub(Config::class);
        $config->method('getEmailMinSeverity')->willReturn('error');
        $config->method('getEmailMaxPerRun')->willReturn(50);
        return new DigestBuilder(
            $groups,
            $events,
            $config,
            $scope,
            new BotDetector($scope),
            new NoiseFilter($scope),
            new TimeFormatter($timezone),
            new PathMapper()
        );
    }

    private function group(int $id, array $overrides = []): array
    {
        return $overrides + [
            'group_id' => $id, 'source' => 'js', 'severity' => 'error', 'error_type' => 'TypeError',
            'message' => 'Problem ' . $id, 'file' => 'app.js', 'line' => 3, 'status' => ErrorGroup::STATUS_NEW,
            'occurrence_count' => 100, 'first_seen_at' => '2026-10-01 00:00:00', 'last_seen_at' => '2026-10-29 06:00:00',
        ];
    }

    public function testCountersAndPreUpgradeEventsAreCombined(): void
    {
        $this->fetchQueue = [
            [['group_id' => 1, 'cnt' => 40, 'bot_cnt' => 10], ['group_id' => 2, 'cnt' => 5, 'bot_cnt' => 5]],
            [['group_id' => 1, 'cnt' => 3, 'last_at' => '2026-10-28 20:00:00']],
            [['group_id' => 1, 'user_agent' => 'Googlebot/2.1', 'flagged' => 0, 'cnt' => 3]],
            [],
            [$this->group(1), $this->group(2)],
        ];
        $now = gmmktime(7, 30, 0, 10, 29, 2026);
        $digest = $this->builder(null, '2026-10-29 01:00:00')->build($now);

        $this->assertSame([1], array_column($digest['rows'], 'group_id'));
        $this->assertSame(43, $digest['rows'][0]['count_24h']);
        $this->assertSame(13, $digest['rows'][0]['bot_count_24h']);
        $this->assertSame('43', $digest['rows'][0]['count_label']);
        $this->assertSame(1, $digest['hidden_bot'], 'bot-only group from counters is hidden');
        $this->assertContains(['hour_start >= ?', '2026-10-29 01:00:00'], $this->where);
        $this->assertContains(['created_at < ?', '2026-10-29 01:00:00'], $this->where);
    }

    public function testRowsUseTheTimezoneOfTheirStoreViewAndResolvedGroupsAreCounted(): void
    {
        $zones = static fn ($type = null, $code = null) => (string)$code === '2' ? 'Asia/Kolkata' : 'America/Chicago';
        $digest = $this->builder($zones)->assemble(
            [
                ['group_id' => 1, 'cnt' => 2, 'last_at' => ''],
                ['group_id' => 2, 'cnt' => 2, 'last_at' => ''],
                ['group_id' => 3, 'cnt' => 2, 'last_at' => ''],
            ],
            [],
            [],
            [
                $this->group(1, ['store_id' => 2]),
                $this->group(2, ['store_id' => 0]),
                $this->group(3, ['status' => ErrorGroup::STATUS_RESOLVED]),
            ],
            ['timezone' => 'America/Chicago', 'event_cap' => 50, 'since' => '2026-10-28 07:30:00']
        );
        $rows = array_column($digest['rows'], null, 'group_id');
        $this->assertSame('2026-10-29 11:30:00 IST', $rows[1]['last_seen']);
        $this->assertSame('2026-10-29 01:00:00 CDT', $rows[2]['last_seen']);
        $this->assertTrue($digest['mixed_timezones']);
        $this->assertSame(1, $digest['resolved_active']);
        $this->assertSame(2, $digest['total_groups']);

        (new DigestHolder())->set($digest);
        $block = new Summary(
            $this->createStub(Context::class),
            $this->createStub(CollectionFactory::class),
            $this->createStub(BackendUrl::class),
            [],
            new DigestHolder()
        );
        $footer = implode("\n", $block->getFooterLines());
        $this->assertStringContainsString('timezone of the store view', $footer);
        $this->assertStringContainsString('1 group(s) had events in the last 24 hours but are already marked as resolved', $footer);
    }

    public function testViewActionAcceptsLinksWithoutASecretKey(): void
    {
        $property = new \ReflectionProperty(ViewController::class, '_publicActions');
        $defaults = (new \ReflectionClass(ViewController::class))->getDefaultProperties();
        $this->assertTrue($property->isProtected());
        $this->assertSame(['view'], $defaults['_publicActions']);
        $this->assertSame('Panth_ErrorMonitor::view', ViewController::ADMIN_RESOURCE);
    }

    public function testUpgradeNoticeIsShownOnlyWhileTheFlagIsSet(): void
    {
        $flag = 1;
        $flags = $this->createStub(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(static function () use (&$flag) {
            return $flag;
        });
        $auth = $this->createStub(AuthorizationInterface::class);
        $auth->method('isAllowed')->willReturn(true);
        $message = new RefingerprintPending($flags, $auth);
        $this->assertTrue($message->isDisplayed());
        $this->assertStringContainsString('panth:errormonitor:refingerprint', (string)$message->getText());
        $flag = null;
        $this->assertFalse($message->isDisplayed());
    }
}
