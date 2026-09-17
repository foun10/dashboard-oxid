<?php
declare(strict_types=1);

namespace foun10\Dashboard\Extension\Application\Controller\Admin;

use foun10\Dashboard\Controller\Admin\DashboardController;
use OxidEsales\Eshop\Core\Registry;

/**
 * The admin's main frame opens with cl=navigation&item=home.tpl, both right
 * after login and from the header's "Home" link. That frame now shows the
 * dashboard instead of the menu overview.
 *
 * parent::render() still runs first: for home.tpl it performs OXID's start-up
 * checks (setup directory left behind, writable config.inc.php, system
 * requirements, update notice). Their messages are handed to the dashboard
 * through the session, so the warnings stay visible.
 */
class NavigationController extends NavigationController_parent
{
    public function render()
    {
        $template = parent::render();

        // "home.tpl" on OXID 6, "home.html.twig" on OXID 7
        if (strtok((string) $template, '.') !== 'home') {
            return $template;
        }

        $messages = $this->_aViewData['aMessage'] ?? [];
        $session = Registry::getSession();

        if (is_array($messages) && array_filter($messages)) {
            $session->setVariable(DashboardController::SESSION_MESSAGES, array_filter($messages));
        } else {
            $session->deleteVariable(DashboardController::SESSION_MESSAGES);
        }

        $url = html_entity_decode((string) $this->getViewConfig()->getSelfLink(), ENT_QUOTES) . 'cl=foun10_dashboard';
        Registry::getUtils()->redirect($url, false, 302);

        return $template;
    }
}
