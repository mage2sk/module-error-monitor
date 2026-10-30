<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Service;

use Panth\ErrorMonitor\Service\JsReasonSerializer;
use PHPUnit\Framework\TestCase;

class JsReasonSerializerTest extends TestCase
{
    private JsReasonSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new JsReasonSerializer();
    }

    public function testTextFieldsArePreferredInOrder(): void
    {
        $this->assertSame('boom', $this->serializer->describe(['message' => 'boom', 'responseText' => 'x']));
        $this->assertSame('<h1>503</h1>', $this->serializer->describe(['responseText' => '<h1>503</h1>', 'statusText' => 'error']));
        $this->assertSame('timeout', $this->serializer->describe(['readyState' => 0, 'statusText' => 'timeout']));
    }

    public function testObjectsWithoutTextAreJsonEncodedAndTruncated(): void
    {
        $this->assertSame('{"status":0,"readyState":4}', $this->serializer->describe(['status' => 0, 'readyState' => 4]));
        $long = $this->serializer->describe(['data' => str_repeat('a', 2000)]);
        $this->assertSame(JsReasonSerializer::MAX_LENGTH, mb_strlen($long));
        $this->assertStringStartsWith('{"data":"aaa', $long);
    }

    public function testDeepNestingFallsBackToPlaceholder(): void
    {
        $deep = ['x' => 1];
        for ($i = 0; $i < 40; $i++) {
            $deep = ['child' => $deep];
        }
        $this->assertSame('[object Object]', $this->serializer->describe($deep));
    }

    public function testScalarsAndEmptyValues(): void
    {
        $this->assertSame('', $this->serializer->describe(null));
        $this->assertSame('', $this->serializer->describe([]));
        $this->assertSame('false', $this->serializer->describe(false));
        $this->assertSame('42', $this->serializer->describe(42));
        $this->assertSame('plain', $this->serializer->describe('  plain '));
    }

    public function testResolveMessageForObjectPayload(): void
    {
        [$message, $type] = $this->serializer->resolveMessage([
            'message' => ['statusText' => 'error', 'status' => 0],
        ]);
        $this->assertSame('error', $message);
        $this->assertSame('object', $type);

        [$message, $type] = $this->serializer->resolveMessage([
            'message' => 'Error: [object Object]',
            'reason' => ['responseText' => 'Session expired'],
            'reasonType' => 'object:Object',
        ]);
        $this->assertSame('Session expired', $message);
        $this->assertSame('object:Object', $type);
    }

    public function testResolveMessageKeepsNormalStrings(): void
    {
        [$message, $type] = $this->serializer->resolveMessage(['message' => 'Real error', 'reason' => ['a' => 1]]);
        $this->assertSame('Real error', $message);
        $this->assertSame('', $type);
        $this->assertTrue($this->serializer->isObjectPlaceholder('Uncaught Error: [object Object]'));
        $this->assertFalse($this->serializer->isObjectPlaceholder('Cannot read [object Object] of x'));
    }

    public function testTypeOf(): void
    {
        $this->assertSame('null', $this->serializer->typeOf(null));
        $this->assertSame('array', $this->serializer->typeOf([1, 2]));
        $this->assertSame('object', $this->serializer->typeOf(['a' => 1]));
        $this->assertSame('number', $this->serializer->typeOf(1.5));
        $this->assertSame('string', $this->serializer->typeOf('x'));
        $this->assertSame('boolean', $this->serializer->typeOf(true));
    }
}
