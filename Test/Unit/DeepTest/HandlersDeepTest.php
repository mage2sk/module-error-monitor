<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\DeepTest;

use GraphQL\Error\Error as GraphQlError;
use GraphQL\Error\FormattedError;
use Magento\Cron\Model\Schedule;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\State;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Adapter\Pdo\Mysql;
use Magento\Framework\DB\Select;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthenticationException;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Exception\GraphQlNoSuchEntityException;
use Magento\Framework\GraphQl\Query\ErrorHandler;
use Magento\Framework\GraphQl\Query\ErrorHandlerInterface;
use Magento\Framework\Logger\LoggerProxy;
use Magento\Framework\Logger\Monolog;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Panth\ErrorMonitor\Helper\Config;
use Panth\ErrorMonitor\Logger\DbHandler;
use Panth\ErrorMonitor\Model\ErrorGroup;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorEvent as ErrorEventResource;
use Panth\ErrorMonitor\Model\ResourceModel\ErrorGroup as ErrorGroupResource;
use Panth\ErrorMonitor\Model\Summary\DigestBuilder;
use Panth\ErrorMonitor\Plugin\Cron\ScheduleContextPlugin;
use Panth\ErrorMonitor\Plugin\GraphQl\ErrorHandlerPlugin;
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
use Panth\ErrorMonitor\Service\TimeFormatter;
use PHPUnit\Framework\Attributes\DataProvider;
use Panth\ErrorMonitor\Test\Unit\MonologRecordTrait;
use PHPUnit\Framework\TestCase;

/**
 * Deep test chunk 6: GraphQL client-safe filter (#7), CLI/cron context + secret masking (#8),
 * preprocessed path mapping (#9).
 */
class HandlersDeepTest extends TestCase
{
    use MonologRecordTrait;

    private array $server = [];

    private array $values = [];

    /** @var ErrorPayload[] */
    private array $payloads = [];

    private array $inserts = [];

    protected function setUp(): void
    {
        $this->server = $_SERVER;
        foreach (['REQUEST_URI', 'HTTP_REFERER', 'HTTP_USER_AGENT', 'REMOTE_ADDR', 'REQUEST_METHOD'] as $key) {
            unset($_SERVER[$key]);
        }
        GraphQlClientErrorFilter::reset();
        CliContext::setCronJob(null);
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->server;
        GraphQlClientErrorFilter::reset();
        CliContext::setCronJob(null);
    }

    // ------------------------------------------------------------------ helpers

    private function scope(): ScopeConfigInterface
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(fn ($path) => in_array($path, [
            'panth_errormonitor/general/enabled',
            'panth_errormonitor/php_capture/enabled',
        ], true));
        $scope->method('getValue')->willReturnCallback(fn ($path) => $this->values[$path] ?? null);
        return $scope;
    }

    private function handler(): DbHandler
    {
        $recorder = $this->createStub(ErrorRecorder::class);
        $recorder->method('record')->willReturnCallback(function (ErrorPayload $payload): ?int {
            $this->payloads[] = $payload;
            return 1;
        });
        $ip = $this->createStub(IpAnonymizer::class);
        $ip->method('process')->willReturnArgument(0);
        $guard = $this->createStub(DeploymentGuard::class);
        $guard->method('isCaptureSuspended')->willReturn(false);
        return new DbHandler($recorder, $this->scope(), $ip, $guard, new Fingerprinter());
    }

    private function record(string $message, string $level = 'error', array $context = [])
    {
        return $this->makeRecord($level, $message, $context);
    }

    private function filter(): GraphQlClientErrorFilter
    {
        return new GraphQlClientErrorFilter($this->scope());
    }

    /** Real Magento LoggerProxy -> Monolog -> DbHandler, exactly as wired by app/etc/di.xml + module di.xml. */
    private function realLogger(): LoggerProxy
    {
        $monolog = new Monolog('main', [$this->handler()]);
        $deployment = $this->createStub(DeploymentConfig::class);
        $deployment->method('get')->willReturn(null);
        $om = $this->createStub(ObjectManagerInterface::class);
        $om->method('get')->willReturnCallback(
            static fn ($class) => $class === DeploymentConfig::class ? $deployment : $monolog
        );
        $om->method('create')->willReturn($monolog);
        return new LoggerProxy($om);
    }

    private function graphQlErrorHandler(LoggerProxy $logger): ErrorHandler
    {
        $state = $this->createStub(State::class);
        $state->method('getMode')->willReturn(State::MODE_DEVELOPER);
        return new ErrorHandler($logger, $state);
    }

    private function recorder(): ErrorRecorder
    {
        $conn = $this->createStub(Mysql::class);
        $conn->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . $v . '`');
        $conn->method('query')->willReturnCallback(function ($sql, $bind = []) {
            $this->inserts[] = ['table' => 'group', 'row' => $bind];
            return null;
        });
        $conn->method('fetchOne')->willReturn('0');
        $conn->method('lastInsertId')->willReturn('7');
        $conn->method('insert')->willReturnCallback(function ($table, array $row): int {
            $this->inserts[] = ['table' => $table, 'row' => $row];
            return 1;
        });
        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $groups->method('getMainTable')->willReturn('panth_error_group');
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($conn);
        $events->method('getMainTable')->willReturn('panth_error_event');
        $throttle = $this->createStub(CaptureThrottle::class);
        $throttle->method('register')->willReturn(1);
        $limiter = $this->createStub(NewGroupLimiter::class);
        $limiter->method('allow')->willReturn(true);
        return new ErrorRecorder($groups, $events, new Fingerprinter(), $this->scope(), $throttle, $limiter);
    }

    private function eventRow(): array
    {
        foreach ($this->inserts as $insert) {
            if ($insert['table'] === 'panth_error_event') {
                return $insert['row'];
            }
        }
        $this->fail('no event row inserted');
    }

    private function digestBuilder(): DigestBuilder
    {
        $scope = $this->createStub(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturn(false);
        $scope->method('getValue')->willReturn(null);
        $select = $this->createStub(Select::class);
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('select')->willReturn($select);
        $groups = $this->createStub(ErrorGroupResource::class);
        $groups->method('getConnection')->willReturn($conn);
        $events = $this->createStub(ErrorEventResource::class);
        $events->method('getConnection')->willReturn($conn);
        $timezone = $this->createStub(TimezoneInterface::class);
        $timezone->method('getConfigTimezone')->willReturn('UTC');
        return new DigestBuilder(
            $groups,
            $events,
            $this->createStub(Config::class),
            $scope,
            new BotDetector($scope),
            new NoiseFilter($scope),
            new TimeFormatter($timezone),
            new PathMapper()
        );
    }

    private function digestFor(array $group, string $minSeverity = 'error'): array
    {
        $group += [
            'group_id' => 1,
            'source' => 'php',
            'severity' => 'error',
            'error_type' => 'RuntimeException',
            'message' => 'x',
            'file' => null,
            'line' => null,
            'status' => ErrorGroup::STATUS_NEW,
            'occurrence_count' => 3,
            'first_seen_at' => '2026-10-28 10:00:00',
            'last_seen_at' => '2026-10-28 11:00:00',
        ];
        return $this->digestBuilder()->assemble(
            [['group_id' => $group['group_id'], 'cnt' => 3, 'last_at' => '2026-10-28 11:00:00']],
            [],
            [],
            [$group],
            ['timezone' => 'UTC', 'min_severity' => $minSeverity, 'max_rows' => 50, 'exclude_bot_only' => true,
                'event_cap' => 50, 'since' => '2026-10-27 12:00:00']
        );
    }

    private function moduleDir(): string
    {
        return dirname(__DIR__, 3);
    }

    // ================================================================== #7 GraphQL

    public function testPluginIsDeclaredOnErrorHandlerInterfaceAsBeforePlugin(): void
    {
        $xml = simplexml_load_file($this->moduleDir() . '/etc/di.xml');
        $nodes = $xml->xpath('//type[@name="Magento\\Framework\\GraphQl\\Query\\ErrorHandlerInterface"]/plugin');
        $this->assertCount(1, $nodes);
        $this->assertSame(ErrorHandlerPlugin::class, (string)$nodes[0]['type']);
        $this->assertNotSame('true', (string)$nodes[0]['disabled']);

        $method = new \ReflectionMethod(ErrorHandlerPlugin::class, 'beforeHandle');
        $params = $method->getParameters();
        $this->assertCount(3, $params);
        $this->assertSame(ErrorHandlerInterface::class, (string)$params[0]->getType());
        $this->assertSame('array', (string)$params[1]->getType());
        $this->assertSame('callable', (string)$params[2]->getType());
        $this->assertFalse(method_exists(ErrorHandlerPlugin::class, 'aroundHandle'));
        $this->assertFalse(method_exists(ErrorHandlerPlugin::class, 'afterHandle'));
    }

    public function testPluginNeverChangesTheClientResponse(): void
    {
        $errors = [
            new GraphQlError('Cannot query field "stock_status" on type "ProductInterface".'),
            new GraphQlError('boom', null, null, null, ['products'], new \RuntimeException('boom')),
            new GraphQlError('x', null, null, null, null, new GraphQlInputException(new Phrase('The region_id does not match the selected country or region'))),
        ];
        $formatter = static fn (\Throwable $e) => FormattedError::createFromException($e);
        $handler = $this->graphQlErrorHandler($this->realLogger());
        $subject = $this->createStub(ErrorHandlerInterface::class);

        $plugin = new ErrorHandlerPlugin($this->filter());
        $this->assertNull($plugin->beforeHandle($subject, $errors, $formatter), 'null = arguments untouched');

        $with = $handler->handle($errors, $formatter);
        GraphQlClientErrorFilter::reset();
        $without = $handler->handle($errors, $formatter);
        $this->assertSame($without, $with);

        // A filter that blows up must not leak out of the plugin either.
        $broken = $this->createStub(GraphQlClientErrorFilter::class);
        $broken->method('register')->willThrowException(new \RuntimeException('filter broke'));
        $this->assertNull((new ErrorHandlerPlugin($broken))->beforeHandle($subject, $errors, $formatter));
    }

    public function testIsClientSafeMatrix(): void
    {
        $f = $this->filter();
        $phrase = static fn (string $t) => new Phrase($t);

        $this->assertTrue($f->isClientSafeThrowable(new GraphQlInputException($phrase('bad input'))));
        $this->assertTrue($f->isClientSafeThrowable(new GraphQlAuthorizationException($phrase('not allowed'))));
        $this->assertTrue($f->isClientSafeThrowable(new GraphQlNoSuchEntityException($phrase('no such product'))));
        $this->assertTrue($f->isClientSafeThrowable(new GraphQlAuthenticationException($phrase('not logged in'))));
        $this->assertFalse($f->isClientSafeThrowable(new GraphQlAuthorizationException($phrase('x'), null, 0, false)));

        // Raw GraphQL\Error\Error: no previous => client safe (schema/validation errors).
        $this->assertTrue($f->isClientSafeThrowable(new GraphQlError('Cannot query field "stock_status" on type "ProductInterface".')));
        // previous = ClientAware: inherits its flag.
        $this->assertTrue($f->isClientSafeThrowable(
            new GraphQlError('x', null, null, null, null, new GraphQlInputException($phrase('The region_id does not match the selected country or region')))
        ));
        // previous = plain server exception => not client safe.
        $this->assertFalse($f->isClientSafeThrowable(new GraphQlError('boom', null, null, null, null, new \RuntimeException('boom'))));
        // previous = plain LocalizedException (not ClientAware) => server fault per webonyx; shown as "Internal server error".
        $this->assertFalse($f->isClientSafeThrowable(new GraphQlError('x', null, null, null, null, new LocalizedException($phrase('x')))));
        // Non-GraphQL throwables are never client safe.
        $this->assertFalse($f->isClientSafeThrowable(new \RuntimeException('x')));

        // Context exception wins over message heuristics.
        $this->assertFalse($f->isClientSafe('GraphQL\\Error\\Error: looks safe', ['exception' => new \RuntimeException('x')]));
    }

    /** @return array<string, array{0: ?string, 1: int, 2: ?string, 3: bool}> */
    public static function modeProvider(): array
    {
        return [
            'default (unset) = skip' => [null, 0, null, false],
            'skip' => ['skip', 0, null, false],
            'notice' => ['notice', 1, 'notice', true],
            'record' => ['record', 1, 'error', false],
            'garbage falls back to skip' => ['nonsense', 0, null, false],
            'case/space tolerant' => [' NOTICE ', 1, 'notice', true],
        ];
    }

    /**
     * @dataProvider modeProvider
     */
    #[DataProvider('modeProvider')]
    public function testThreeConfigModesThroughTheRealLoggerChain(?string $mode, int $count, ?string $severity, bool $flag): void
    {
        $this->values[GraphQlClientErrorFilter::XML_MODE] = $mode;
        $errors = [new GraphQlError('Cannot query field "stock_status" on type "ProductInterface".')];
        $handler = $this->graphQlErrorHandler($this->realLogger());
        (new ErrorHandlerPlugin($this->filter()))->beforeHandle($handler, $errors, static fn ($e) => []);
        $handler->handle($errors, static fn ($e) => []);

        $this->assertCount($count, $this->payloads);
        if ($count === 1) {
            $this->assertSame($severity, $this->payloads[0]->severity);
            $this->assertSame(GraphQlError::class, $this->payloads[0]->type);
            $this->assertSame($flag, !empty($this->payloads[0]->context[GraphQlClientErrorFilter::CONTEXT_FLAG]));
        }
    }

    public function testRegionIdInputErrorAndAggregatedErrorsAreClientSafe(): void
    {
        $input = new GraphQlInputException(new Phrase('The region_id does not match the selected country or region'));
        $input->addError(new GraphQlInputException(new Phrase('The specified region is not a part of the selected country or region')));
        $errors = [new GraphQlError($input->getMessage(), null, null, null, ['setShippingAddressesOnCart'], $input)];
        $handler = $this->graphQlErrorHandler($this->realLogger());
        $handler->handle($errors, static fn ($e) => []);
        $this->assertSame([], $this->payloads, 'skip mode: outer error + aggregated error both dropped');

        $this->values[GraphQlClientErrorFilter::XML_MODE] = 'notice';
        $handler->handle($errors, static fn ($e) => []);
        $this->assertCount(2, $this->payloads);
        foreach ($this->payloads as $payload) {
            $this->assertSame('notice', $payload->severity);
        }
    }

    public function testRealServerFaultInsideResolverIsStillRecordedAsErrorOnce(): void
    {
        $fault = new \RuntimeException('SQLSTATE[HY000] [2002] Connection refused');
        $errors = [new GraphQlError($fault->getMessage(), null, null, null, ['products'], $fault)];
        $handler = $this->graphQlErrorHandler($this->realLogger());
        (new ErrorHandlerPlugin($this->filter()))->beforeHandle($handler, $errors, static fn ($e) => []);
        $handler->handle($errors, static fn ($e) => []);

        $this->assertCount(1, $this->payloads, 'one Monolog record => exactly one DB payload (no double recording)');
        $p = $this->payloads[0];
        $this->assertSame('error', $p->severity);
        $this->assertSame(GraphQlError::class, $p->type);
        $this->assertStringContainsString('Connection refused', $p->message);
        $this->assertStringContainsString('RuntimeException', implode(' ', (array)($p->context['previous'] ?? [])));
        $this->assertArrayNotHasKey(GraphQlClientErrorFilter::CONTEXT_FLAG, $p->context);
    }

    public function testRegisteredClientMessageDoesNotHideAServerFaultLoggedWithException(): void
    {
        $f = $this->filter();
        $f->register([new GraphQlError('Not found')]);
        $this->assertTrue($f->isClientSafe('Not found'));
        $this->assertFalse($f->isClientSafe('Not found', ['exception' => new \RuntimeException('Not found')]));
    }

    /**
     * DEFECT (major for headless/PWA stores): in production mode Magento's GraphQl controller handles
     * GraphQlAuthenticationException / GraphQlAuthorizationException thrown outside the executor
     * (e.g. expired customer bearer token -> HTTP 401) via ExceptionFormatter::create(), which logs
     * logger->critical(new \Exception("Report ID: graph-ql-<id>; Message: ...", 0, $clientSafe)).
     * The wrapper is a plain \Exception, so the filter records a client error as CRITICAL.
     */
    public function testProductionReportIdWrapperOfClientSafeErrorIsTreatedAsClientError(): void
    {
        $auth = new GraphQlAuthenticationException(new Phrase("The current customer isn't authorized."));
        $wrapper = new \Exception('Report ID: graph-ql-6720f1a3b9c4d; Message: ' . $auth->getMessage(), 0, $auth);
        $this->realLogger()->critical($wrapper);
        $this->assertSame([], $this->payloads, 'client-safe auth error wrapped by ExceptionFormatter was recorded as critical');
    }

    public function testNoticeGraphQlGroupsAreKeptOutOfTheEmail(): void
    {
        foreach ([GraphQlError::class, GraphQlInputException::class] as $type) {
            $digest = $this->digestFor(['severity' => 'notice', 'error_type' => $type], 'notice');
            $this->assertSame([], $digest['rows'], $type);
            $this->assertSame(1, $digest['hidden_graphql'], $type);
        }
        // A real error with the same type is still listed.
        $digest = $this->digestFor(['severity' => 'error', 'error_type' => GraphQlError::class]);
        $this->assertCount(1, $digest['rows']);
    }

    // ================================================================== #8 CLI / cron

    public function testSapiIsCliUnderPhpUnit(): void
    {
        $this->assertSame(PHP_SAPI, (new CliContext())->sapi());
        $this->assertSame('cli', (new CliContext())->sapi());
    }

    /** @return array<string, array{0: array, 1: string}> */
    public static function maskedArgvProvider(): array
    {
        $hex = str_repeat('a1b2c3d4', 5);
        $jwt = 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjMifQ.abcDEF123ghiJKL456';
        return [
            '--password=x' => [['bin/magento', 'x:y', '--password=x'], 'bin/magento x:y --password=***'],
            '--admin-password=' => [
                ['bin/magento', 'admin:user:create', '--admin-user=bob', '--admin-password=Secret123!'],
                'bin/magento admin:user:create --admin-user=bob --admin-password=***',
            ],
            '--admin-password separated' => [
                ['bin/magento', 'admin:user:create', '--admin-password', 'Secret123!'],
                'bin/magento admin:user:create --admin-password ***',
            ],
            '--key x' => [['bin/magento', 'x', '--key', 'abc'], 'bin/magento x --key ***'],
            '--encryption-key=' => [['bin/magento', 'setup:install', '--key=abc'], 'bin/magento setup:install --key=***'],
            '--db-password' => [['bin/magento', 'setup:install', '--db-password=pw'], 'bin/magento setup:install --db-password=***'],
            'url creds positional' => [
                ['bin/magento', 'x', 'amqp://guest:s3cret@rabbit:5672/'],
                'bin/magento x amqp://guest:***@rabbit:5672/',
            ],
            'url creds in option' => [
                ['bin/magento', 'x', '--url=https://u:pw@host/x'],
                'bin/magento x --url=https://u:***@host/x',
            ],
            'long hex token positional' => [['bin/magento', 'x', $hex], 'bin/magento x ***'],
            'jwt in option' => [['bin/magento', 'x', '--bearer=' . $jwt], 'bin/magento x --bearer=***'],
            'plain args untouched' => [
                ['bin/magento', 'indexer:reindex', 'catalogsearch_fulltext', '--group=index'],
                'bin/magento indexer:reindex catalogsearch_fulltext --group=index',
            ],
            'paths untouched' => [
                ['bin/magento', 'x', '/var/www/html/var/import/products_2026_10_29_export.csv'],
                'bin/magento x /var/www/html/var/import/products_2026_10_29_export.csv',
            ],
            'non-scalar ignored' => [['bin/magento', ['a'], 'x'], 'bin/magento x'],
        ];
    }

    /**
     * @dataProvider maskedArgvProvider
     */
    #[DataProvider('maskedArgvProvider')]
    public function testArgvMasking(array $argv, string $expected): void
    {
        $this->assertSame($expected, (new CliContext())->sanitizeArgv($argv));
    }

    public function testArgvIsCappedAt1000Chars(): void
    {
        $line = (new CliContext())->sanitizeArgv(['bin/magento', str_repeat('a ', 2000)]);
        $this->assertSame(1000, mb_strlen($line));
    }

    /** DEFECT (minor): short "-p <secret>" form is not masked. */
    public function testShortDashPPasswordIsMasked(): void
    {
        $this->assertSame('mysql -u root -p ***', (new CliContext())->sanitizeArgv(['mysql', '-u', 'root', '-p', 'hunter2']));
    }

    /** DEFECT (minor): DB DSN passwords (no user:pass@ URL form) leak. */
    public function testDbDsnPasswordIsMasked(): void
    {
        $line = (new CliContext())->sanitizeArgv(['bin/magento', 'x', '--dsn=mysql:host=db;dbname=magento;user=m;password=hunter2']);
        $this->assertStringNotContainsString('hunter2', $line);
    }

    /** DEFECT (minor, PII): admin:user:create --admin-email is stored in clear in context.argv. */
    public function testAdminEmailIsNotStoredInArgv(): void
    {
        $line = (new CliContext())->sanitizeArgv([
            'bin/magento', 'admin:user:create', '--admin-user=bob', '--admin-email=bob.smith@example.com',
            '--admin-firstname=Bob', '--admin-lastname=Smith', '--admin-password=Secret123!',
        ]);
        $this->assertStringNotContainsString('Secret123', $line);
        $this->assertStringNotContainsString('bob.smith@example.com', $line);
    }

    /**
     * DEFECT (minor, false positive): Magento's own cron spawns
     * "bin/magento cron:run --group=default --bootstrap=standaloneProcessStarted=1"; the value is masked
     * by looksSecret() (>=24 chars, letters+digit, '=' allowed) and the useful context is lost.
     */
    public function testMagentoCronBootstrapArgIsNotMasked(): void
    {
        $line = (new CliContext())->sanitizeArgv(['bin/magento', 'cron:run', '--group=default', '--bootstrap=standaloneProcessStarted=1']);
        $this->assertSame('bin/magento cron:run --group=default --bootstrap=standaloneProcessStarted=1', $line);
    }

    private function schedule(string $code, string $status): Schedule
    {
        $schedule = $this->getMockBuilder(Schedule::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $schedule->setData('job_code', $code);
        $schedule->setData('status', $status);
        return $schedule;
    }

    public function testCronJobCodeLifecycleViaSchedulePlugin(): void
    {
        $plugin = new ScheduleContextPlugin();
        $s = $this->schedule('indexer_reindex_all_invalid', Schedule::STATUS_PENDING);

        $this->assertFalse($plugin->afterTryLockJob($s, false));
        $this->assertNull(CliContext::getCronJob(), 'lock not acquired => not set');

        $this->assertTrue($plugin->afterTryLockJob($s, true));
        $this->assertSame('indexer_reindex_all_invalid', CliContext::getCronJob());

        $s->setData('status', Schedule::STATUS_RUNNING);
        $plugin->afterSave($s, $s);
        $this->assertSame('indexer_reindex_all_invalid', CliContext::getCronJob(), 'running save keeps it');

        $other = $this->schedule('other_job', Schedule::STATUS_SUCCESS);
        $plugin->afterSave($other, $other);
        $this->assertSame('indexer_reindex_all_invalid', CliContext::getCronJob(), 'other job save does not clear');

        $ctx = (new CliContext())->collect('anything', ['bin/magento', 'cron:run']);
        $this->assertSame('indexer_reindex_all_invalid', $ctx['cron_job']);

        $s->setData('status', Schedule::STATUS_ERROR);
        $plugin->afterSave($s, $s);
        $this->assertNull(CliContext::getCronJob(), 'final save clears it');
    }

    public function testCronJobFallbackFromMessageAndNoCrashOutsideCron(): void
    {
        $c = new CliContext();
        $this->assertSame(
            'sales_clean_quotes',
            $c->collect('Cron Job sales_clean_quotes has an error: boom. Statistics: {"sum":0.1}', [])['cron_job']
        );
        $ctx = $c->collect('plain CLI error', []);
        $this->assertSame(['sapi' => 'cli'], $ctx);
        $this->assertNull($c->cronJobFromMessage('Cron Job  has an error'));
        CliContext::setCronJob('   ');
        $this->assertNull(CliContext::getCronJob());
    }

    public function testCliEventKeepsFullExceptionContextAndArgv(): void
    {
        $_SERVER['argv'] = ['bin/magento', 'setup:upgrade', '--db-password=topsecret'];
        $root = new \InvalidArgumentException('root cause');
        $e = new \RuntimeException('outer failure', 3, new \LogicException('middle', 0, $root));
        $this->handler()->handle($this->record('outer failure', 'critical', ['exception' => $e]));

        $this->assertCount(1, $this->payloads);
        $p = $this->payloads[0];
        $this->assertSame(\RuntimeException::class, $p->type);
        $this->assertSame(__FILE__, $p->file);
        $this->assertSame(\RuntimeException::class, $p->context['exception_class']);
        $this->assertSame(__FILE__, $p->context['exception_file']);
        $this->assertIsInt($p->context['exception_line']);
        $this->assertCount(2, $p->context['previous']);
        $this->assertStringStartsWith('LogicException: middle (', $p->context['previous'][0]);
        $this->assertStringStartsWith('InvalidArgumentException: root cause (', $p->context['previous'][1]);
        $this->assertSame('cli', $p->context['sapi']);
        $this->assertSame('bin/magento setup:upgrade --db-password=***', $p->context['argv']);
        $this->assertNotNull($p->stackTrace);
        $this->assertNull($p->url);
    }

    public function testPreviousChainIsLimitedToFive(): void
    {
        $e = new \RuntimeException('level 0');
        for ($i = 1; $i <= 9; $i++) {
            $e = new \RuntimeException('level ' . $i, 0, $e);
        }
        $this->handler()->handle($this->record('x', 'error', ['exception' => $e]));
        $this->assertCount(5, $this->payloads[0]->context['previous']);
    }

    public function testCliEventWithoutExceptionGetsBoundedBacktrace(): void
    {
        $this->handler()->handle($this->record('Cron Job foo has an error: bar. Statistics: {"sum":1}'));
        $p = $this->payloads[0];
        $this->assertNotNull($p->stackTrace);
        $this->assertLessThanOrEqual(30, substr_count($p->stackTrace, "\n") + 1);
        $this->assertStringNotContainsString('/vendor/monolog/', $p->stackTrace);
        $this->assertSame('foo', $p->context['cron_job']);
    }

    public function testHttpRequestDoesNotGetCliContext(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/graphql';
        $this->handler()->handle($this->record('server fault'));
        $this->assertArrayNotHasKey('sapi', $this->payloads[0]->context);
        $this->assertArrayNotHasKey('argv', $this->payloads[0]->context);
    }

    public function testHugeStackTraceIsCappedForTheDbColumn(): void
    {
        $this->recorder()->record(new ErrorPayload(
            source: 'php',
            severity: 'error',
            type: 'RuntimeException',
            message: 'deep recursion',
            stackTrace: str_repeat("#0 /var/www/html/vendor/x/y.php(12): A->b()\n", 5000)
        ));
        $this->assertSame(60000, mb_strlen($this->eventRow()['stack_trace']));
    }

    /**
     * DEFECT (minor): scalar context values are never capped (DbHandler::contextValue only caps arrays),
     * and panth_error_event.context is TEXT (65,535 bytes). Magento runs with SQL_MODE='' so MySQL
     * silently truncates the JSON -> the stored context is invalid JSON and the admin view shows raw text.
     */
    public function testStoredContextFitsTheTextColumnAndStaysValidJson(): void
    {
        $this->handler()->handle($this->record('x', 'error', ['response_body' => str_repeat('A', 70000)]));
        $this->recorder()->record($this->payloads[0]);
        $json = (string)$this->eventRow()['context'];
        $this->assertLessThanOrEqual(65535, strlen($json));
        $this->assertIsArray(json_decode($json, true));
    }

    public function testPiiAndSecretsInLogContextAreRedacted(): void
    {
        $this->handler()->handle($this->record('checkout failed', 'error', [
            'email' => 'john@example.com',
            'customer_email' => 'john@example.com',
            'password' => 'pw',
            'telephone' => '0123',
            'payment' => ['cc_number' => '4111111111111111', 'cvv' => '123'],
            'order_id' => 100,
        ]));
        $this->recorder()->record($this->payloads[0]);
        $ctx = json_decode((string)$this->eventRow()['context'], true);
        foreach (['email', 'customer_email', 'password', 'telephone'] as $key) {
            $this->assertSame('redacted', $ctx[$key], $key);
        }
        $this->assertSame('redacted', $ctx['payment']['cc_number']);
        $this->assertSame('redacted', $ctx['payment']['cvv']);
        $this->assertSame(100, $ctx['order_id']);
    }

    // ================================================================== #9 paths

    /** @return array<string, array{0: ?string, 1: ?string}> */
    public static function pathProvider(): array
    {
        $rel = 'vendor/magento/module-review/view/frontend/templates/form.phtml';
        return [
            'owner sample (relative)' => ['var/view_preprocessed/pub/static/' . $rel, $rel],
            'absolute root' => ['/var/www/html/var/view_preprocessed/pub/static/' . $rel, '/var/www/html/' . $rel],
            'root under /var' => ['/var/var/view_preprocessed/pub/static/' . $rel, '/var/' . $rel],
            'app/design theme' => [
                '/srv/shop/var/view_preprocessed/pub/static/app/design/frontend/Hyva/default/Magento_Review/templates/form.phtml',
                '/srv/shop/app/design/frontend/Hyva/default/Magento_Review/templates/form.phtml',
            ],
            'app/code' => [
                'var/view_preprocessed/pub/static/app/code/Panth/Foo/view/frontend/templates/a.phtml',
                'app/code/Panth/Foo/view/frontend/templates/a.phtml',
            ],
            'windows drive, forward slashes' => [
                'C:/xampp/htdocs/m2/var/view_preprocessed/pub/static/' . $rel,
                'C:/xampp/htdocs/m2/' . $rel,
            ],
            // Not mapped (documented; Magento does not support Windows hosts in production).
            'windows backslashes untouched' => [
                'C:\\m2\\var\\view_preprocessed\\pub\\static\\vendor\\a.phtml',
                'C:\\m2\\var\\view_preprocessed\\pub\\static\\vendor\\a.phtml',
            ],
            'deployed static untouched' => [
                '/var/www/html/pub/static/frontend/Magento/luma/en_US/js/a.js',
                '/var/www/html/pub/static/frontend/Magento/luma/en_US/js/a.js',
            ],
            'view_preprocessed without pub/static untouched' => [
                '/var/www/html/var/view_preprocessed/source/a.less',
                '/var/www/html/var/view_preprocessed/source/a.less',
            ],
            'similar dir name untouched' => [
                '/var/www/html/myvar/view_preprocessed/pub/static/a.phtml',
                '/var/www/html/myvar/view_preprocessed/pub/static/a.phtml',
            ],
            'source path untouched' => ['/var/www/html/' . $rel, '/var/www/html/' . $rel],
            'null' => [null, null],
            'empty' => ['', ''],
        ];
    }

    /**
     * @dataProvider pathProvider
     */
    #[DataProvider('pathProvider')]
    public function testToSource(?string $in, ?string $out): void
    {
        $this->assertSame($out, (new PathMapper())->toSource($in));
    }

    public function testFingerprintIsIdenticalForPreprocessedAndSourcePath(): void
    {
        $fp = new Fingerprinter();
        $rel = 'vendor/magento/module-review/view/frontend/templates/form.phtml';
        $this->assertSame(
            $fp->fingerprint('php', 'Error', 'Call to a member function x() on null', $rel, 1),
            $fp->fingerprint('php', 'Error', 'Call to a member function x() on null', 'var/view_preprocessed/pub/static/' . $rel, 1)
        );
    }

    public function testRecorderStoresSourcePathAndKeepsPreprocessedFileAndLineNote(): void
    {
        $pre = '/var/www/html/var/view_preprocessed/pub/static/vendor/magento/module-review/view/frontend/templates/form.phtml';
        $this->recorder()->record(new ErrorPayload(
            source: 'php',
            severity: 'error',
            type: 'Error',
            message: 'Call to a member function getId() on null',
            file: $pre,
            line: 1
        ));
        $groupBind = $this->inserts[0]['row'];
        $this->assertSame('/var/www/html/vendor/magento/module-review/view/frontend/templates/form.phtml', $groupBind[5]);
        $ctx = json_decode((string)$this->eventRow()['context'], true);
        $this->assertSame($pre, $ctx['preprocessed_file']);
        $this->assertSame(PathMapper::LINE_NOTE, $ctx['line_note']);
    }

    public function testEmailFlagsLineOneOfMappedTemplateAsUnreliable(): void
    {
        $digest = $this->digestFor([
            'file' => 'vendor/magento/module-review/view/frontend/templates/form.phtml',
            'line' => 1,
        ]);
        $this->assertTrue($digest['rows'][0]['line_unreliable']);
    }

    /**
     * DEFECT (minor): the group stores the MAPPED source path, so the email (DigestBuilder ->
     * PathMapper::isLineUnreliable) can no longer tell that the line came from a minified/preprocessed
     * copy. A preprocessed template error on line 7 gets no "line may be unreliable" note in the email;
     * the note survives only as raw JSON (context.line_note) on the admin event view.
     */
    public function testEmailFlagsAnyLineOfATemplateThatCameFromViewPreprocessed(): void
    {
        $this->recorder()->record(new ErrorPayload(
            source: 'php',
            severity: 'error',
            type: 'Error',
            message: 'Call to a member function getId() on null',
            file: 'var/view_preprocessed/pub/static/vendor/magento/module-review/view/frontend/templates/form.phtml',
            line: 7
        ));
        $groupBind = $this->inserts[0]['row'];
        $this->assertSame(1, $groupBind[12], 'group row stores the line_unreliable flag');
        $digest = $this->digestFor(['file' => $groupBind[5], 'line' => $groupBind[6], 'line_unreliable' => $groupBind[12]]);
        $this->assertTrue($digest['rows'][0]['line_unreliable']);
    }
}
