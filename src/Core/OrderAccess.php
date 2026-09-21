<?php
declare(strict_types=1);

namespace foun10\Dashboard\Core;

use DOMDocument;
use DOMXPath;

/**
 * Decides whether the current admin user may see the dashboard at all.
 *
 * The dashboard is a report on orders, so it follows the order rights: it is shown to whoever
 * may open Administer Orders, and to nobody else. The Enterprise Edition can take that away
 * from a role, and then a sales dashboard is exactly the wrong start page.
 *
 * Asked of the admin menu rather than of a rights API: the menu OXID builds for the logged-in
 * user already has everything removed that the user may not reach - in EE through the roles,
 * in every edition through the user rights, the user's groups and whatever modules changed.
 * The check therefore needs no edition-specific code and works the same on CE and EE.
 */
class OrderAccess
{
    /** The admin menu entry for orders: <SUBMENU id="mxdisplayorders" cl="admin_order" list="order_list"> */
    public const ORDER_CONTROLLER = 'admin_order';
    public const ORDER_LIST = 'order_list';

    /**
     * True when the order menu entry survived in this user's menu.
     *
     * Without a menu the answer is no: the dashboard shows revenue, and a state we cannot
     * read is not a permission. The user then sees OXID's own start page instead.
     */
    public function isGranted(?DOMDocument $menu): bool
    {
        if ($menu === null) {
            return false;
        }

        // a constant expression, so query() returns a node list and never false
        return (new DOMXPath($menu))
            ->query(sprintf("//*[@cl='%s' or @list='%s']", self::ORDER_CONTROLLER, self::ORDER_LIST))
            ->length > 0;
    }
}
