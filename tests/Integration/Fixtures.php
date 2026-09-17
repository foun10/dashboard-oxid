<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Integration;

use OxidEsales\Eshop\Core\DatabaseProvider;

/**
 * Orders, customers, articles and baskets with a known outcome, written straight into the
 * shop's tables. Every row carries the OXID prefix below, and the orders are dated March 2011 -
 * far away from any real or generated data - so the assertions can be exact.
 */
final class Fixtures
{
    public const PREFIX = 'f10dit';
    public const FROM = '2011-03-01';
    public const TO = '2011-03-31';

    public static function remove(): void
    {
        $like = self::PREFIX . '%';
        foreach ([
            'oxorderarticles' => 'OXORDERID',
            'oxorder' => 'OXID',
            'oxuserbasketitems' => 'OXBASKETID',
            'oxuserbaskets' => 'OXID',
            'oxuser' => 'OXID',
            'oxartextends' => 'OXID',
            'oxarticles' => 'OXID',
            'oxseo' => 'OXOBJECTID',
        ] as $table => $column) {
            self::db()->execute("DELETE FROM $table WHERE $column LIKE ?", [$like]);
        }
    }

    public static function create(): void
    {
        self::remove();

        self::article('parent', '', 'F10DIT-P', 'Fixture Jacket', 59.50, 0, 2, 3);
        self::article('var1', self::id('parent'), 'F10DIT-P-S', '', 0, 3, 0, 0);
        self::article('var2', self::id('parent'), 'F10DIT-P-M', '', 0, 0, 0, 0);
        self::article('simple', '', 'F10DIT-S', 'Fixture Mug', 25.00, 0, 0, 0);

        self::user('userA', 'fixture-a@example.com', 'secret', '2011-03-01 08:00:00');
        self::user('userC', 'fixture-c@example.com', '', '2011-03-15 08:00:00');
        self::user('userD', 'fixture-d@example.com', 'secret', '2010-06-01 08:00:00');
        // the admin area checks the logged-in user's rights; registered long ago, so no new customer
        self::user('admin', 'fixture-admin@example.com', 'secret', '2009-01-01 08:00:00', 'malladmin');
        // a customer of another shop - their basket is not ours
        self::user('otherShop', 'fixture-other@example.com', 'secret', '2009-01-01 08:00:00', 'user', 2);

        $de = self::countryId('DE');
        $at = self::countryId('AT');
        $ch = self::countryId('CH');

        // A: 119.00 gross incl. 19.00 VAT, 4.90 shipping -> 100.00 net; delivered to Austria
        self::order('A', 'userA', 'fixture-a@example.com', '2011-03-01 10:00:00', [
            'OXTOTALORDERSUM' => 123.90, 'OXDELCOST' => 4.90, 'OXARTVAT1' => 19, 'OXARTVATPRICE1' => 19.00,
            'OXBILLCOUNTRYID' => $de, 'OXDELCOUNTRYID' => $at, 'OXPAYMENTTYPE' => 'oxidinvoice',
        ]);
        self::line('A', 1, 'var1', 1, 100.00);

        // B: returning customer, 11.90 voucher: 107.10 gross incl. 17.10 VAT -> 90.00 net
        self::order('B', 'userA', 'fixture-a@example.com', '2011-03-05 10:00:00', [
            'OXTOTALORDERSUM' => 107.10, 'OXVOUCHERDISCOUNT' => 11.90, 'OXARTVAT1' => 19, 'OXARTVATPRICE1' => 17.10,
            'OXBILLCOUNTRYID' => $de, 'OXPAYMENTTYPE' => 'oxidinvoice',
        ]);
        self::line('B', 1, 'var2', 1, 90.00);

        // C: guest, VAT-free export in CHF at 1.5: 150.00 goods + 7.50 shipping -> 100.00 net
        self::order('C', 'userC', 'fixture-c@example.com', '2011-03-15 10:00:00', [
            'OXTOTALORDERSUM' => 157.50, 'OXDELCOST' => 7.50, 'OXARTVAT1' => 0, 'OXARTVATPRICE1' => 0,
            'OXCURRENCY' => 'CHF', 'OXCURRATE' => 1.5,
            'OXBILLCOUNTRYID' => $ch, 'OXPAYMENTTYPE' => 'oxidpayadvance',
        ]);
        self::line('C', 1, 'simple', 3, 150.00);

        // an earlier *cancelled* order of the guest does not make C a returning customer
        self::order('Cold', 'userC', 'fixture-c@example.com', '2011-01-10 10:00:00', [
            'OXTOTALORDERSUM' => 50.00, 'OXARTVAT1' => 19, 'OXARTVATPRICE1' => 7.98,
            'OXBILLCOUNTRYID' => $ch, 'OXPAYMENTTYPE' => 'oxidpayadvance', 'OXSTORNO' => 1,
        ]);

        // D: implausible stored VAT (negative) - falls back to the rate: 119.00 / 1.19 -> 100.00
        self::order('D', 'userD', 'fixture-d@example.com', '2011-03-20 10:00:00', [
            'OXTOTALORDERSUM' => 119.00, 'OXARTVAT1' => 19, 'OXARTVATPRICE1' => -1,
            'OXBILLCOUNTRYID' => $de, 'OXPAYMENTTYPE' => 'f10dit_unknown',
        ]);
        self::line('D', 1, 'simple', 1, 100.00);
        self::line('D', 2, 'simple', 5, 500.00, 1);

        // H: returning, cash on delivery plus wrapping and gift card:
        // 238.00 gross incl. 38.00 VAT + 7.50 + 2.00 + 1.00 -> 200.00 net
        self::order('H', 'userA', 'fixture-a@example.com', '2011-03-10 10:00:00', [
            'OXTOTALORDERSUM' => 248.50, 'OXPAYCOST' => 7.50, 'OXWRAPCOST' => 2.00, 'OXGIFTCARDCOST' => 1.00,
            'OXARTVAT1' => 19, 'OXARTVATPRICE1' => 38.00,
            'OXBILLCOUNTRYID' => $de, 'OXPAYMENTTYPE' => 'oxidcashondel',
        ]);
        self::line('H', 1, 'var1', 2, 200.00);

        // excluded: cancelled, not finished, another shop
        foreach ([
            'E' => ['OXSTORNO' => 1],
            'F' => ['OXTRANSSTATUS' => 'NOT_FINISHED'],
            'G' => ['OXSHOPID' => 2],
        ] as $key => $extra) {
            self::order($key, 'userD', 'fixture-d@example.com', '2011-03-02 10:00:00', $extra + [
                'OXTOTALORDERSUM' => 1190.00, 'OXARTVAT1' => 19, 'OXARTVATPRICE1' => 190.00,
                'OXBILLCOUNTRYID' => $de, 'OXPAYMENTTYPE' => 'oxidinvoice',
            ]);
            self::line($key, 1, 'var1', 100, 1000.00);
        }

        // a fresh saved basket (counted), an old one and a notice list (both not)
        self::basket('fresh', 'userA', 'savedbasket', 0, [['var1', 20]]);
        self::basket('old', 'userA', 'savedbasket', 49 * 60, [['simple', 1]]);
        self::basket('notice', 'userD', 'noticelist', 0, [['simple', 1]]);
        self::basket('other', 'otherShop', 'savedbasket', 0, [['simple', 2]]);
    }

    public static function id(string $name): string
    {
        return substr(self::PREFIX . $name, 0, 32);
    }

    public static function countryId(string $iso): string
    {
        return (string) self::db()->getOne('SELECT OXID FROM oxcountry WHERE OXISOALPHA2 = ?', [$iso]);
    }

    private static function article(
        string $name,
        string $parentId,
        string $artnum,
        string $title,
        float $price,
        float $stock,
        int $varCount,
        float $varStock
    ): void {
        self::db()->execute(
            "INSERT INTO oxarticles (OXID, OXSHOPID, OXPARENTID, OXACTIVE, OXARTNUM, OXTITLE, OXTITLE_1, OXPRICE,
                OXSTOCK, OXVARCOUNT, OXVARSTOCK, OXINSERT, OXUPDATEPRICETIME)
            VALUES (?, 1, ?, 1, ?, ?, ?, ?, ?, ?, ?, '2011-01-01', '2011-01-01 00:00:00')",
            [self::id($name), $parentId, $artnum, $title, $title, $price, $stock, $varCount, $varStock]
        );
        self::db()->execute(
            "INSERT INTO oxartextends (OXID, OXLONGDESC, OXLONGDESC_1, OXLONGDESC_2, OXLONGDESC_3) VALUES (?, '', '', '', '')",
            [self::id($name)]
        );
    }

    private static function user(
        string $name,
        string $email,
        string $password,
        string $registered,
        string $rights = 'user',
        int $shopId = 1
    ): void {
        self::db()->execute(
            "INSERT INTO oxuser (OXID, OXACTIVE, OXRIGHTS, OXSHOPID, OXUSERNAME, OXPASSWORD, OXPASSSALT,
                OXFNAME, OXLNAME, OXREGISTER)
            VALUES (?, 1, ?, ?, ?, ?, '', 'Fixture', ?, ?)",
            [self::id($name), $rights, $shopId, $email, $password, ucfirst($name), $registered]
        );
    }

    private static function order(string $key, string $user, string $email, string $date, array $values): void
    {
        $row = $values + [
            'OXID' => self::id('order' . $key),
            'OXSHOPID' => 1,
            'OXUSERID' => self::id($user),
            'OXORDERDATE' => $date,
            'OXBILLEMAIL' => $email,
            'OXDELCOUNTRYID' => '',
            'OXDELCOST' => 0,
            'OXPAYCOST' => 0,
            'OXWRAPCOST' => 0,
            'OXGIFTCARDCOST' => 0,
            'OXVOUCHERDISCOUNT' => 0,
            'OXARTVATPRICE2' => 0,
            'OXCURRENCY' => 'EUR',
            'OXCURRATE' => 1,
            'OXTRANSSTATUS' => 'OK',
            'OXSTORNO' => 0,
            'OXCARDTEXT' => '',
            'OXREMARK' => '',
        ];

        self::db()->execute(
            'INSERT INTO oxorder (' . implode(', ', array_keys($row)) . ') VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')',
            array_values($row)
        );
    }

    private static function line(string $order, int $number, string $article, float $amount, float $net, int $storno = 0): void
    {
        self::db()->execute(
            "INSERT INTO oxorderarticles (OXID, OXORDERID, OXAMOUNT, OXARTID, OXARTNUM, OXTITLE, OXNETPRICE,
                OXSTORNO, OXORDERSHOPID, OXPERSPARAM)
            VALUES (?, ?, ?, ?, 'ORDERED-NR', 'Ordered title', ?, ?, 1, '')",
            [self::id('line' . $order . $number), self::id('order' . $order), $amount, self::id($article), $net, $storno]
        );
    }

    /**
     * @param array<int, array{0: string, 1: float}> $items
     */
    private static function basket(string $name, string $user, string $title, int $minutesAgo, array $items): void
    {
        self::db()->execute(
            'INSERT INTO oxuserbaskets (OXID, OXUSERID, OXTITLE, OXTIMESTAMP)
            VALUES (?, ?, ?, NOW() - INTERVAL ? MINUTE)',
            [self::id('basket' . $name), self::id($user), $title, $minutesAgo]
        );
        foreach ($items as $index => [$article, $amount]) {
            self::db()->execute(
                "INSERT INTO oxuserbasketitems (OXID, OXBASKETID, OXARTID, OXAMOUNT, OXSELLIST, OXPERSPARAM)
                VALUES (?, ?, ?, ?, '', '')",
                [self::id('bitem' . $name . $index), self::id('basket' . $name), self::id($article), $amount]
            );
        }
    }

    /**
     * Fetched per call: getDb() sets the fetch mode on the shared connection, and any shop
     * code running in between may have switched it back.
     */
    private static function db()
    {
        return DatabaseProvider::getDb(DatabaseProvider::FETCH_MODE_ASSOC);
    }
}
