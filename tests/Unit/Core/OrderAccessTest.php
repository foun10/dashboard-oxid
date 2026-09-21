<?php

declare(strict_types=1);

namespace foun10\Dashboard\Tests\Unit\Core;

use DOMDocument;
use foun10\Dashboard\Core\OrderAccess;
use PHPUnit\Framework\TestCase;

final class OrderAccessTest extends TestCase
{
    /** @var OrderAccess */
    private $access;

    protected function setUp(): void
    {
        $this->access = new OrderAccess();
    }

    public function testTheOrderMenuEntryGrantsAccess(): void
    {
        self::assertTrue($this->access->isGranted($this->menu(
            '<MAINMENU id="mxorders">'
            . '<SUBMENU id="mxdisplayorders" cl="admin_order" list="order_list" idx="1"/>'
            . '</MAINMENU>'
        )));
    }

    /**
     * How the Enterprise Edition takes the dashboard away: a role without order rights loses
     * the menu entry, and with it the last entry of its main menu.
     */
    public function testAMenuWithoutTheOrderEntryDeniesAccess(): void
    {
        self::assertFalse($this->access->isGranted($this->menu(
            '<MAINMENU id="mxarticles">'
            . '<SUBMENU id="mxarticle" cl="article_list" list="article_list"/>'
            . '</MAINMENU>'
        )));
    }

    public function testTheListAttributeAloneIsEnough(): void
    {
        self::assertTrue($this->access->isGranted($this->menu('<SUBMENU id="x" list="order_list"/>')));
    }

    public function testAnEntryMentioningOrdersElsewhereDoesNotGrantAccess(): void
    {
        self::assertFalse($this->access->isGranted($this->menu(
            '<SUBMENU id="mxdisplayorders" cl="admin_order_statistics" list="order_statistics"/>'
        )));
    }

    public function testAnEmptyMenuDeniesAccess(): void
    {
        self::assertFalse($this->access->isGranted($this->menu('')));
    }

    /**
     * Without a menu there is nothing to read the permission from, and the dashboard reports
     * revenue - so the answer is no, not "probably fine".
     */
    public function testNoMenuDeniesAccess(): void
    {
        self::assertFalse($this->access->isGranted(null));
    }

    private function menu(string $inner): DOMDocument
    {
        $dom = new DOMDocument();
        $dom->loadXML('<OX>' . $inner . '</OX>');

        return $dom;
    }
}
