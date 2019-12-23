<?php
/**
 * @package plugins
 * @copyright Copyright 2003-2018 Zen Cart Development Team
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: Drbyte Sun Dec 23 12:42:13 2019 -0500 New in v3.2.1 $
 */

/**
 * This observer is used to help with price listing for those that have attributes that have quantity
 * discounts based on the attributes selected.  This file is not required for normal use and
 * may be removed (along with the includes/auto_loaders/config.get_products_base_price_attr_qty_price.php file).
 * This file supports identifying the lowest priced attribute when working through the
 * lowest/next price for unselected attributes where one or more has attribute quantity discounts.
 *
 * Modifications to Zen Cart core files may be necessary for versions before 1.5.6.
 */
class zcObserverGetProductsBasePriceAttrQtyPrice extends base {

  protected $products_base_price;

  public function __construct() {
    $attachArray = array();
    $attachArray[] = 'ZEN_GET_PRODUCTS_BASE_PRICE';
    $attachArray[] = 'DPU_NOTIFY_INSERT_PRODUCT_QUERY';
    $this->attach($this, $attachArray);
  }

  /**
   * Set/adjust the products base price
   * In ZC 1.5.6:  $zco_notifier->notify('ZEN_GET_PRODUCTS_BASE_PRICE', $products_id, $products_base_price, $base_price_is_handled);
   * @param string $eventID name of the observer event fired
   * @param integer $products_id passed without being forced to be an integer.
   * @param value $base_price_is_handled boolean? passed by reference
   */
  protected function updateZenGetProductsBasePrice(&$callingClass, $eventID, $products_id, &$products_base_price, &$base_price_is_handled)
  {
    global $db;
    
      $product_check = $db->Execute("select products_price, products_priced_by_attribute from " . TABLE_PRODUCTS . " where products_id = " . (int)$products_id);

// is there a products_price to add to attributes
      $products_price = $product_check->fields['products_price'];

      // do not select display only attributes and select attributes_price_base_included is true
//      $product_att_query = $db->Execute("select options_id, price_prefix, options_values_price, attributes_display_only, attributes_price_base_included, attributes_qty_prices, round(concat(price_prefix, options_values_price), 5) as value from " . TABLE_PRODUCTS_ATTRIBUTES . " where products_id = '" . (int)$products_id . "' and attributes_display_only != '1' and attributes_price_base_included='1'". " order by options_id, value");
      $product_att_query = $db->Execute("select options_id, price_prefix, options_values_price, attributes_display_only, attributes_price_base_included, attributes_qty_prices,
       (round(concat(price_prefix, options_values_price) +
              if (locate(',', attributes_qty_prices),
                  substr(attributes_qty_prices, locate(':', attributes_qty_prices) + 1, locate(',', attributes_qty_prices) - locate(':', attributes_qty_prices) - 1),
                  substr(attributes_qty_prices, locate(':', attributes_qty_prices) + 1)
                 )
              , 5)
       ) as value from " . TABLE_PRODUCTS_ATTRIBUTES . " where products_id = " . (int)$products_id . " and attributes_display_only != '1' and attributes_price_base_included='1'". " order by options_id, value");

      $the_options_id= 'x';
      $the_base_price= 0;
// add attributes price to price if priced by attributes or base price of zero and has attributes (discount by attribute quantity) or has attribute quantity discounts
      if (($product_check->fields['products_priced_by_attribute'] == '1' && $product_att_query->RecordCount() >= 1) || ($products_price == 0 && $product_att_query->RecordCount() >= 1)) {
//      if ($product_check->fields['products_priced_by_attribute'] == '1' and $product_att_query->RecordCount() >= 1) {
        while (!$product_att_query->EOF) {
          if ( $the_options_id != $product_att_query->fields['options_id']) {
            $the_options_id = $product_att_query->fields['options_id'];
            $the_base_price += (($product_att_query->fields['price_prefix'] == '-') ? -1 : 1) * $product_att_query->fields['options_values_price'];
            if ($product_att_query->fields['attributes_qty_prices'] != '') {
              $the_base_price += zen_get_attributes_qty_prices_onetime($product_att_query->fields['attributes_qty_prices'], 1/* Shouldn't this be the minimum quantity that is allowed to be purchased? Such as the buy now quantity?*/);
            }
          }
          $product_att_query->MoveNext();
        }

        $the_base_price = $products_price + $the_base_price;
      } else {
        $the_base_price = $products_price;
//            if ($product_att_query->fields['attributes_qty_prices'] != '') {
//              $the_base_price += zen_get_attributes_qty_prices_onetime($product_att_query->fields['attributes_qty_prices'], 1/* Shouldn't this be the minimum quantity that is allowed to be purchased? Such as the buy now quantity?*/);
//            }
      }

    $products_base_price = $the_base_price;
    $base_price_is_handled = true;
  }

  protected function updateDPUNotifyInsertProductQuery(&$callingClass, $eventID, $products_id, &$base_price_is_handled) {
    
    $callingClass->product_attr_query = "select pa.options_id, pa.options_values_id, pa.attributes_display_only, pa.attributes_price_base_included, po.products_options_type, round((concat(pa.price_prefix, pa.options_values_price) +
            if (locate(',', attributes_qty_prices),
                substr(attributes_qty_prices, locate(':', attributes_qty_prices) + 1, locate(',', attributes_qty_prices) - locate(':', attributes_qty_prices) - 1), 
                substr(attributes_qty_prices, locate(':', attributes_qty_prices) + 1)
               )
      ), 5) as value from " . TABLE_PRODUCTS_ATTRIBUTES . " pa LEFT JOIN " . TABLE_PRODUCTS_OPTIONS . " po on (po.products_options_id = pa.options_id) where products_id = " . (int)$products_id . " and attributes_display_only != '1' and attributes_price_base_included='1'". " order by pa.options_id, value";
    $base_price_is_handled = true;
  }

  protected function update(&$callingClass, $eventID, $param1) {
    if ($eventID == ZEN_GET_PRODUCTS_BASE_PRICE) {
      $this->updateZenGetProductsBasePrice($callingClass, $eventID, $param1, false);
    }
    if ($eventID == DPU_NOTIFY_INSERT_PRODUCT_QUERY) {
      $this->products_base_price = 0.0;
      $products_base_price_handled = false;

      $this->updateDPUNotifyInsertProductQuery($callingClass, $eventID, $param1, $this->products_base_price, $products_base_price_handled);
    }
  }
}
