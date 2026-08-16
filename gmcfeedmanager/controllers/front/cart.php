<?php

declare(strict_types=1);

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * "Direct to cart" endpoint: /module/gmcfeedmanager/cart?id={id}&qty=1
 *
 * $id is exactly the module's own g:id format (simple product "102" or
 * combination "102_45"). This is what feed.php's g:checkout_link_template
 * points Google at: Google replaces the literal "{id}" token in that
 * template with each item's own g:id before showing the link to a
 * shopper, so this controller has to accept that same combined form
 * rather than plain PrestaShop id_product/id_product_attribute params.
 *
 * No token/auth: this is a public "add to cart" deep link clicked by
 * anonymous shoppers coming from an ad, exactly like any storefront
 * "Add to cart" button. It only ever mutates the requester's own session
 * cart, so it carries the same trust level as that button, not an
 * administrative action.
 */
class GmcfeedmanagerCartModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ssl = false;

    public function setMedia($isAjax = false): void
    {
        // Intentionally empty: this controller always redirects, so no
        // front-office assets are ever rendered.
    }

    public function initContent(): void
    {
        [$idProduct, $idProductAttribute] = $this->parseOfferId((string) Tools::getValue('id', ''));
        $qty = max(1, (int) Tools::getValue('qty', 1));

        if ($idProduct <= 0) {
            $this->redirectToSafety();
        }

        $product = new Product($idProduct, false, (int) $this->context->language->id, (int) $this->context->shop->id);

        if (!Validate::isLoadedObject($product) || !$product->active) {
            $this->redirectToSafety();
        }

        if ($idProductAttribute > 0) {
            $combination = new Combination($idProductAttribute);
            if (!Validate::isLoadedObject($combination) || (int) $combination->id_product !== $idProduct) {
                // Bad/stale combination id: still send the shopper
                // somewhere useful rather than failing outright.
                $idProductAttribute = 0;
            }
        }

        // Mirrors PrestaShop's own front CartController::processAdd(): the
        // context cart from init() has no id until first saved, and the
        // id has to land in the cookie or the cart page the shopper lands
        // on next will not find it.
        if (!$this->context->cart->id) {
            $this->context->cart->add();
            if (Validate::isLoadedObject($this->context->cart)) {
                $this->context->cookie->id_cart = (int) $this->context->cart->id;
            }
        }

        $added = $this->context->cart->updateQty($qty, $idProduct, $idProductAttribute ?: null);

        if ($added > 0) {
            Tools::redirect($this->context->link->getPageLink('cart', null, null, ['action' => 'show']));
        }

        // Could not add (out of stock, minimum quantity, invalid
        // combination...): land on the product page instead of a dead end.
        Tools::redirect($this->context->link->getProductLink($product));
    }

    /**
     * @return array{0: int, 1: int} [id_product, id_product_attribute]
     */
    private function parseOfferId(string $offerId): array
    {
        if (!preg_match('/^(\d+)(?:_(\d+))?$/', $offerId, $matches)) {
            return [0, 0];
        }

        return [(int) $matches[1], isset($matches[2]) ? (int) $matches[2] : 0];
    }

    private function redirectToSafety(): void
    {
        Tools::redirect($this->context->link->getPageLink('index'));
    }
}
