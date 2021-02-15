<?php

declare(strict_types=1);

namespace MStilkerich\Tests\CardDavAddressbook4Roundcube\Unit;

use PHPUnit\Framework\TestCase;
use MStilkerich\Tests\CardDavAddressbook4Roundcube\TestInfrastructure;
use MStilkerich\CardDavAddressbook4Roundcube\Addressbook;
use MStilkerich\CardDavAddressbook4Roundcube\Db\AbstractDatabase;
use MStilkerich\CardDavAddressbook4Roundcube\Db\Database;
use MStilkerich\CardDavClient\AddressbookCollection;

/**
 * @psalm-import-type FullAbookRow from AbstractDatabase
 */
final class AddressbookTest extends TestCase
{
    /** @var JsonDatabase */
    private $db;

    public static function setUpBeforeClass(): void
    {
        $_SESSION['user_id'] = 105;
    }

    public function setUp(): void
    {
        $this->db = new JsonDatabase();
        $cache = $this->createMock(\rcube_cache::class);
        TestInfrastructure::init($this->db);
        TestInfrastructure::$infra->setCache($cache);
    }

    public function tearDown(): void
    {
        Utils::cleanupTempImages();
        TestInfrastructure::logger()->reset();
    }

    private function createAbook(): Addressbook
    {
        $db = $this->db;
        $db->importData('tests/unit/data/syncHandlerTest/initial/db.json');

        /** @var FullAbookRow */
        $abookcfg = $db->lookup("42", '*', 'addressbooks');

        $abook = new Addressbook("42", $abookcfg, false, []);
        $davobj = $this->createStub(AddressbookCollection::class);
        $davobj->method('downloadResource')->will($this->returnCallback([Utils::class, 'downloadResource']));
        TestInfrastructure::setPrivateProperty($abook, 'davAbook', $davobj);

        return $abook;
    }

    /**
     * Tests that a newly constructed addressbook has the expected values in its public properties.
     */
    public function testAddressbookHasExpectedPublicPropertyValues(): void
    {
        $db = $this->db;

        $abook = $this->createAbook();

        $this->assertSame('id', $abook->primary_key);
        $this->assertSame(true, $abook->groups);
        $this->assertSame(true, $abook->export_groups);
        $this->assertSame(false, $abook->readonly);
        $this->assertSame(false, $abook->searchonly);
        $this->assertSame(false, $abook->undelete);
        $this->assertSame(true, $abook->ready);
        $this->assertSame('name', $abook->sort_col);
        $this->assertSame('ASC', $abook->sort_order);
        $this->assertSame(['birthday', 'anniversary'], $abook->date_cols);

        $this->assertIsArray($abook->coltypes['email']);
        $this->assertIsArray($abook->coltypes['email']['subtypes']);
        $this->assertContains("SpecialLabel", $abook->coltypes['email']['subtypes']);

        $this->assertSame("Test Addressbook", $abook->get_name());
        $this->assertSame("42", $abook->getId());

        /** @var FullAbookRow */
        $abookcfg = $db->lookup("42", '*', 'addressbooks');
        $roAbook = new Addressbook("42", $abookcfg, true, []);
        $this->assertSame(true, $roAbook->readonly);
    }

    /**
     * @return list<array{int,string,?string,int,int,list<string>}>
     */
    public function listRecordsDataProvider(): array
    {
        return [
            // subset, sort_col, sort_order, page, pagesize, expRecords
            [ 0, 'name', 'ASC', 1, 10, [ "56", "51", "50", "52", "53", "54" ] ],
            [ 0, 'name', 'DESC', 1, 10, [ "54", "53", "52", "50", "51", "56" ] ],
            [ 0, 'firstname', null, 1, 10, [ "51", "52", "53", "56", "50", "54" ] ],
        ];
    }

    /**
     * Tests list records
     *
     * Inputs besides params: page_size, list_page, filter, group_id, sort_col, sort_order
     *
     * @dataProvider listRecordsDataProvider
     * @param list<string> $expRecords
     */
    public function testListRecordsWithAllColumns(
        int $subset,
        string $sortCol,
        ?string $sortOrder,
        int $page,
        int $pagesize,
        array $expRecords
    ): void {
        $abook = $this->createAbook();
        $abook->set_page($page);
        $this->assertSame($page, $abook->list_page);
        $abook->set_pagesize($pagesize);
        $this->assertSame($pagesize, $abook->page_size);
        $abook->set_sort_order($sortCol, $sortOrder);
        $this->assertSame($sortCol, $abook->sort_col);
        $this->assertSame($sortOrder ?? 'ASC', $abook->sort_order);

        $rset = $abook->list_records(null, $subset);
        $this->assertNull($abook->get_error());
        $this->assertSame(0, $rset->first);
        $this->assertFalse($rset->searchonly);
        $this->assertSame(count($expRecords), $rset->count);
        $this->assertCount(count($expRecords), $rset->records);
        $this->assertCount(count($expRecords), $rset->records);

        $lrOrder = array_column($rset->records, 'ID');
        $this->assertSame($expRecords, $lrOrder, "Card order mismatch");
        for ($i = 0; $i < count($expRecords); ++$i) {
            $id = $expRecords[$i];
            $fn = "tests/unit/data/addressbookTest/c{$id}.json";
            $saveDataExp = Utils::readSaveDataFromJson($fn);
            /** @var array $saveDataRc */
            $saveDataRc = $rset->records[$i];
            Utils::compareSaveData($saveDataExp, $saveDataRc, "Unexpected record data $id");

            if (isset($saveDataExp['photo']) && empty($saveDataExp['photo'])) {
                $this->assertPhotoDownloadWarning();
            }
        }
    }

    /**
     * Asserts that a warning message concerning failure to download the photo has been issued for VCards where an
     * invalid Photo URI is used.
     */
    private function assertPhotoDownloadWarning(): void
    {
        $logger = TestInfrastructure::logger();
        $logger->expectMessage(
            'warning',
            'downloadPhoto: Attempt to download photo from http://localhost/doesNotExist.jpg failed'
        );
    }
}

// vim: ts=4:sw=4:expandtab:fenc=utf8:ff=unix:tw=120
