<?php
declare(strict_types=1);

namespace Panth\ErrorMonitor\Test\Unit\Ui\Component\Listing;

use Magento\Framework\Api\Filter;
use Magento\Framework\Data\Collection;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Panth\ErrorMonitor\Ui\Component\Listing\LikeFulltextFilter;
use PHPUnit\Framework\TestCase;

class LikeFulltextFilterTest extends TestCase
{
    private array $wheres = [];

    private array $likeValues = [];

    private function dbCollection(): AbstractDb
    {
        $conn = $this->createStub(AdapterInterface::class);
        $conn->method('quoteIdentifier')->willReturnCallback(static fn ($v) => '`' . $v . '`');
        $conn->method('quoteInto')->willReturnCallback(function ($text, $value) {
            $this->likeValues[] = $value;
            return str_replace('?', "'" . $value . "'", $text);
        });
        $select = $this->createStub(Select::class);
        $select->method('where')->willReturnCallback(function ($cond) use ($select) {
            $this->wheres[] = $cond;
            return $select;
        });
        $collection = $this->createStub(AbstractDb::class);
        $collection->method('getConnection')->willReturn($conn);
        $collection->method('getSelect')->willReturn($select);
        return $collection;
    }

    private function filter($value): Filter
    {
        return new Filter(['value' => $value]);
    }

    public function testSearchesEveryConfiguredColumn(): void
    {
        $applier = new LikeFulltextFilter(['message', 'file', 42, null]);
        $applier->apply($this->dbCollection(), $this->filter('  boom  '));

        $this->assertSame(["`message` LIKE '%boom%' OR `file` LIKE '%boom%'"], $this->wheres);
    }

    public function testWildcardsAndBackslashesAreEscaped(): void
    {
        (new LikeFulltextFilter(['message']))->apply($this->dbCollection(), $this->filter('50%_off\\x'));
        $this->assertSame(['%50\\%\\_off\\\\x%'], $this->likeValues);
    }

    public function testValueIsTruncatedToTwoHundredCharacters(): void
    {
        (new LikeFulltextFilter(['message']))->apply($this->dbCollection(), $this->filter(str_repeat('a', 300)));
        $this->assertSame('%' . str_repeat('a', 200) . '%', $this->likeValues[0]);
    }

    public function testBlankOrNonScalarValuesAreIgnored(): void
    {
        $applier = new LikeFulltextFilter(['message']);
        $applier->apply($this->dbCollection(), $this->filter('   '));
        $applier->apply($this->dbCollection(), $this->filter(['x']));
        $applier->apply($this->dbCollection(), $this->filter(null));
        $this->assertSame([], $this->wheres);
    }

    public function testNoColumnsOrNonDbCollectionIsIgnored(): void
    {
        (new LikeFulltextFilter([1, 2]))->apply($this->dbCollection(), $this->filter('boom'));
        (new LikeFulltextFilter())->apply($this->dbCollection(), $this->filter('boom'));
        (new LikeFulltextFilter(['message']))->apply($this->createStub(Collection::class), $this->filter('boom'));
        $this->assertSame([], $this->wheres);
    }

    public function testNumericValueIsSearchedAsText(): void
    {
        (new LikeFulltextFilter(['message']))->apply($this->dbCollection(), $this->filter(404));
        $this->assertSame(['%404%'], $this->likeValues);
    }
}
