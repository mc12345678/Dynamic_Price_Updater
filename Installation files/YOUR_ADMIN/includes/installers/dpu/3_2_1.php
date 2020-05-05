<?php
/**
 * @package functions
 * @copyright Copyright 2003-2017 Zen Cart Development Team
 * @copyright Portions Copyright 2003 osCommerce
 * @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
 * @version $Id: mc12345678 thanks to bislewl 6/9/2015
 */
/*
  V3.2.1, What changed:
  - Updated included ajax.php file to what is provided in Zen Cart 1.5.6 which is also backwards compatible.
  - Added an observer to handle/process the display of quantity discounts.
  - Further improved customer information display of the status of selections as compared to available options.
  - Provided prefix information in html_entity_decode format to align with how content is provided in base Zen Cart.
  - Added tax to the prediscount value to support display of the "normalprice" (original price before associated 
      discount was applied when display price with tax is enabled for the storefront.  See issue 16:
      https://github.com/mc12345678/Dynamic_Price_Updater/issues/16
  - Revised out-of-stock information for two things: one to remove issue(s) with PHP 7.x and 2) to attempt to identify
      the stock that is available associated with the selection(s).  One issue noted in some cases is that the stock
      may not be accurate if no selections have yet been made.
  - Added code to support providing an image to support out-of-stock indication which could be modified for other image information.
  - Added code to respond to the configuration option to identify where the out-of-stock image should be displayed. 
      Options available are: replace, left, right, price_replace_only
      The first three options replace the stock available quantity (assuming displayed) and the last option replaces the price
      with the out-of-stock image.
  - Refactored code to move assignment of variables outside of the foreach loop.
  - Added notifier DPU_NOTIFY_INSERT_PRODUCT_QUERY to support revision of query used to identify attributes to process.
  - Refactored logic comparison 'and' for '&&' to provide more consistent order of operations.
  - Refactored code to identify if the selected attribute is for display only or not by adding a variable, this also
      added use of the evaluation of whether the attribute requires/expects to have selections/sub-selections.
  - Added notifier NOTIFY_DYNAMIC_PRICE_UPDATER_DISPLAY_ONLY to further support identifying whether an attribute
      is considered display only or not in price determination.
  - Added a consideration factor to further support identifying attributes that need to be identified; however, have not
      yet been selected by the customer to support reporting the "best" price available based on selections made.
  - Addressed strict processing notices for cart quantities and refactored to simplify code understanding.
  - Added code to identify/determine if an option name has an affect on price to support/simplify store front operations when
      customer is making selections.  Part of the intent is to reduce screen display and data transfer as options are made
      or changed.  This does not micro-manage options such that if the current option and the next option offer the same price
      the screen will be refreshed as if the new selection may have an affect.
  - Added cart quantity information for the sidebox (if used).
  - Incorporated code that determines if an option name requires/expects a selection as a method to the DPU class.
  - Updated language files to incorporate a non-blank space (&nbsp;) to align with the methods used by Zen Cart in this area.
  - Added language code to support stock display.
  - Added/updated conditions at which the javascript for DPU should not be loaded:
    - If the products_id is identified as zero (0) which is a non-existent product.
    - The price of the product is based on requiring a call to the store.
    - If the product's price is considered free and if $optionsIds is set that it is not empty.
    - That the store's status is greater than 0 which is to not display prices to the customer.
    - If the DPU class file does not exist on the system (in case some but not all software has been removed).
    - If the product does not have attributes that could affect prices and either the quantity box is not provided or
        the max is 1 (Though it is possible to offer product in small quantities with a maximum of 1, but this module doesn't
        yet have all of that considered).
    - If the product's price is zero and there are no options that could affect the product's price.
  - Modified the URL generation for reference to site options to address potential SEO indications to either have the full URL
      to the ajax.php file (via zen_href_link) or to just have the ajax.php file referenced.
  - Incorporated javascript checking for the console.log so that do not try to push to the log if that call is a non-function.
  - Built the link to execute the ajax code using Zen Cart's zen_href_link, but removing the portion referencing ajax.php so that
      the page does not have a single path to the code that returns the DPU data, but instead is assembled by page content.
  - Improved event listening as some mobile devices did not appear to trigger DPU.
*/


$zc150 = (PROJECT_VERSION_MAJOR > 1 || (PROJECT_VERSION_MAJOR == 1 && substr(PROJECT_VERSION_MINOR, 0, 3) >= 5));
if ($zc150) { // continue Zen Cart 1.5.0
// @TODO: NEED TO ADD stock display location record...



/*
$sort_order = array(
                array('configuration_group_id' => array('value' => $configuration_group_id,
                                                   'type' => 'integer'),
                      'configuration_key' => array('value' => 'DPU_ATTRIBUTES_MULTI_PRICE_TEXT',
                                                   'type' => 'string'),
                      'configuration_title' => array('value' => 'Show alternate text for partial selection',
                                                   'type' => 'string'),
                      'configuration_value' => array('value' => 'start_at_least',
                                                   'type' => 'string'),
                      'configuration_description' => array('value' => 'When selections are being made that affect the price of the product, what alternate text if any should be shown to the customer.  For example if when no selections have been made, the ZC starting at text may be displayed.  When one selection of many has been made, then the text may be changed to at least this amount indicating that there are selections to be made that could increase the price.  Then once all selections have been made as expected/required the text is or should change to something like Your Price:.<br /><br /><b>Default: start_at_least</b><br /><br />start_at_least: display applicable start at or at least text<br />start_at: display start_at text until all selections have been made<br />at_least: once a selection has been made that does not complete selection display the at_least text.',
                                                   'type' => 'string'),
                      'date_added' => array('value' => 'NOW()',
                                                   'type' => 'noquotestring'),
                      'use_function' => array('value' => 'NULL',
                                                   'type' => 'noquotestring'),
                      'set_function' => array('value' => 'zen_cfg_select_option(array(\'none\', \'start_at_least\', \'start_at\', \'at_least\'),',
                                                   'type' => 'string'),
                      ),
                array('configuration_group_id' => array('value' => $configuration_group_id,
                                                   'type' => 'integer'),
                      'configuration_key' => array('value' => 'DPU_SHOW_OUT_OF_STOCK_IMAGE',
                                                   'type' => 'string'),
                      'configuration_title' => array('value' => 'Show or update the display of out-of-stock',
                                                   'type' => 'string'),
                      'configuration_value' => array('value' => 'quantity_replace',
                                                   'type' => 'string'),
                      'configuration_description' => array('value' => 'Allows display of the current stock status of a product while the customer remains on the product information page and offers control about the ajax update when the product is identified as out-of-stock.<br /><br /><b>default: quantity_replace</b><br /><br />quantity_replace: if incorporated, instead of showing the quantity of product, display DPU_OUT_OF_STOCK_IMAGE.<br />after: display DPU_OUT_OF_STOCK_IMAGE after the quantity display.<br />before: display DPU_OUT_OF_STOCK_IMAGE before the quantity display.<br />price_replace_only: update the price of the product to display DPU_OUT_OF_STOCK_IMAGE',
                                                   'type' => 'string'),
                      'date_added' => array('value' => 'NOW()',
                                                   'type' => 'noquotestring'),
                      'use_function' => array('value' => 'NULL',
                                                   'type' => 'noquotestring'),
                      'set_function' => array('value' => 'zen_cfg_select_option(array(\'quantity_replace\', \'after\', \'before\', \'price_replace_only\'),',
                                                   'type' => 'string'),
                      ),
                array('configuration_group_id' => array('value' => $configuration_group_id,
                                                   'type' => 'integer'),
                      'configuration_key' => array('value' => 'DPU_PROCESS_ATTRIBUTES',
                                                   'type' => 'string'),
                      'configuration_title' => array('value' => 'Modify minimum attribute display price',
                                                   'type' => 'string'),
                      'configuration_value' => array('value' => 'all',
                                                   'type' => 'string'),
                      'configuration_description' => array('value' => 'On what should the minimum display price be based for product with attributes? <br /><br />Only product that are priced by attribute or for all product that have attributes?<br /><br /><b>Default: all</b>',
                                                   'type' => 'string'),
                      'date_added' => array('value' => 'NOW()',
                                                   'type' => 'noquotestring'),
                      'use_function' => array('value' => 'NULL',
                                                   'type' => 'noquotestring'),
                      'set_function' => array('value' => 'zen_cfg_select_option(array(\'all\', \'priced_by\'),',
                                                   'type' => 'string'),
                      ),
                array('configuration_group_id' => array('value' => $configuration_group_id,
                                                   'type' => 'integer'),
                      'configuration_key' => array('value' => 'DPU_PRODUCTDETAILSLIST_PRODUCT_INFO_QUANTITY',
                                                   'type' => 'string'),
                      'configuration_title' => array('value' => 'Where to display the product_quantity',
                                                   'type' => 'string'),
                      'configuration_value' => array('value' => 'productDetailsList_product_info_quantity',
                                                   'type' => 'string'),
                      'configuration_description' => array('value' => 'This is the ID where your product quantity is displayed.<br /><br /><b>default => productDetailsList_product_info_quantity</b>',
                                                   'type' => 'string'),
                      'date_added' => array('value' => 'NOW()',
                                                   'type' => 'noquotestring'),
                      'use_function' => array('value' => 'NULL',
                                                   'type' => 'noquotestring'),
                      'set_function' => array('value' => 'NULL',
                                                   'type' => 'noquotestring'),
                      ),
                );

    $oldcount_sort_sql = "SELECT MAX(sort_order) as max_sort FROM `". TABLE_CONFIGURATION ."` WHERE configuration_group_id=" . (int)$configuration_group_id;
    $oldcount_sort = $db->Execute($oldcount_sort_sql);

    foreach ($sort_order as $config_key => $config_item) {
        $sql = "INSERT INTO " . TABLE_CONFIGURATION . " (configuration_group_id, configuration_key, configuration_title, configuration_value, configuration_description, sort_order, date_added, use_function, set_function)
          VALUES (:configuration_group_id:, :configuration_key:, :configuration_title:, :configuration_value:, :configuration_description:, :sort_order:, :date_added:, :use_function:, :set_function:)
          ON DUPLICATE KEY UPDATE sort_order = :sort_order:";
        $sql = $db->bindVars($sql, ':configuration_group_id:', $config_item['configuration_group_id']['value'], $config_item['configuration_group_id']['type']);
        $sql = $db->bindVars($sql, ':configuration_key:', $config_item['configuration_key']['value'], $config_item['configuration_key']['type']);
        $sql = $db->bindVars($sql, ':configuration_title:', $config_item['configuration_title']['value'], $config_item['configuration_title']['type']);
        $sql = $db->bindVars($sql, ':configuration_value:', $config_item['configuration_value']['value'], $config_item['configuration_value']['type']);
        $sql = $db->bindVars($sql, ':configuration_description:', $config_item['configuration_description']['value'], $config_item['configuration_description']['type']);
        $sql = $db->bindVars($sql, ':sort_order:', (int)$oldcount_sort->fields['max_sort'] + ((int)$config_key + 1) * 10, 'integer');
        $sql = $db->bindVars($sql, ':date_added:', $config_item['date_added']['value'], $config_item['date_added']['type']);
        $sql = $db->bindVars($sql, ':use_function:', $config_item['use_function']['value'], $config_item['use_function']['type']);
        $sql = $db->bindVars($sql, ':set_function:', $config_item['set_function']['value'], $config_item['set_function']['type']);
        $db->Execute($sql);
    }*/

} // END OF VERSION 1.5.x INSTALL
