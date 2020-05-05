<?php
/*
 * Dynamic Price Updater V3.0
 * @copyright Dan Parry (Chrome) / Erik Kerkhoven (Design75)
 * @original author Dan Parry (Chrome)
 * This module is released under the GNU/GPL licence
 */
if (!defined('IS_ADMIN_FLAG')) {
  die('Illegal Access');
}

class DPU extends base {

  /*
   * Local instantiation of the shopping cart
   *
   * @var object
   */
  protected $shoppingCart;
  /*
   * The type of message being sent (error or success)
   *
   * @var string
   */
  protected $responseType = 'success';
  /*
   * Array of lines to be sent back.  The key of the array provides the attribute to identify it at the client side
   * The array value is the text to be inserted into the node
   *
   * @var array
   */
  var $responseText = array();

  /*
   * Array of attributes that could be associated with the product but have not been added by the customer to support
   *   identifying the minimum price of a product from the point of having selected an attribute when other attributes have not
   *   been selected.  (This is a setup contrary to recommendations by ZC, but is a condition that perhaps is best addressed regardless.)
   * @var array
   */
  protected $new_attributes = array();

  /*
   * Array of temporary attributes.
   * @var array
   */
  protected $new_temp_attributes = array();

  /**
   * - query to be stored with class usable in observers with older Zen Cart versions.
   **/
  protected $product_attr_query;

  /*
   * Constructor
   *
   * @param obj The Zen Cart database class
   * @return DPU
   */
  public function __construct() {
//    global $db; // Variable unused.
    // grab the shopping cart class and instantiate it
    $this->shoppingCart = new shoppingCart();
  }

  /*
   * Wrapper to call all methods to generate the output
   *
   * @return void
   */
  public function getDetails($outputType = "XML") {
    $this->setCurrentPage();
    $this->insertProduct();
    $this->shoppingCart->calculate();
    $this->removeExtraSelections();
    $show_dynamic_price_updater_sidebox = true;
    if ($show_dynamic_price_updater_sidebox == true) {
      $this->getSideboxContent();
    }
    $this->prepareOutput();
    $this->dumpOutput($outputType);
  }

  /*
   * Wrapper to call all methods relating to returning multiple prices for category pages etc.
   *
   * @return void
   */
  public function getMulti() {
    $this->insertProducts();
  }

  /*
   * Prepares the shoppingCart contents for transmission
   *
   * @return void
   */
  protected function prepareOutput() {
    global $currencies, $db;
    $this->prefix = '';
    $this->preDiscPrefix = '';

    if (!defined('DPU_ATTRIBUTES_MULTI_PRICE_TEXT')) define('DPU_ATTRIBUTES_MULTI_PRICE_TEXT', 'start_at_least');

    $this->priceDisplay = DPU_ATTRIBUTES_MULTI_PRICE_TEXT;
    $this->notify('NOTIFY_DYNAMIC_PRICE_UPDATER_PREPARE_PRICE_DISPLAY');

    switch (true) {
        //case ($this->product_stock <= 0 && (($this->num_options == $this->unused && !empty($this->new_temp_attributes)) || ($this->num_options > $this->unused && !empty($this->unused)))):
        case ($this->attributeDisplayStartAtPrices() && !empty($this->new_temp_attributes) && ((!isset($this->num_options) && !isset($this->unused)) || (isset($this->num_options) && isset($this->unused) && ($this->num_options == $this->unused)))):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT_STARTING_AT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT_STARTING_AT);
            break;
        case ($this->attributeDisplayAtLeastPrices() && isset($this->num_options) && (!empty($this->unused) && ($this->num_options > $this->unused))):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT_AT_LEAST);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT_AT_LEAST);
            break;
        case (!isset($_POST['pspClass'])):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            break;
        case ($_POST['pspClass'] == "productSpecialPrice"):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            break;
        case ($_POST['pspClass'] == "productSalePrice"):
            $this->prefix = html_entity_decode(PRODUCT_PRICE_SALE);
            $this->preDiscPrefix = html_entity_decode(PRODUCT_PRICE_SALE);
            break;
        case ($_POST['pspClass'] == "productSpecialPriceSale"):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            break;
        case ($_POST['pspClass'] == "productPriceDiscount"):
            $this->prefix = html_entity_decode(PRODUCT_PRICE_DISCOUNT_PREFIX);
            $this->preDiscPrefix = html_entity_decode(PRODUCT_PRICE_DISCOUNT_PREFIX);
            break;
        case ($_POST['pspClass'] == "normalprice"):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            break;
        case ($_POST['pspClass'] == "productFreePrice"):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            break;
        case ($_POST['pspClass'] == "productBasePrice"):
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            break;
        default:
            $this->prefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            $this->preDiscPrefix = html_entity_decode(UPDATER_PREFIX_TEXT);
            // Add a notifier to allow updating this prefix if the ones above do not exist.
            $this->notify('NOTIFY_DYNAMIC_PRICE_UPDATER_PREPARE_OUTPUT_PSP_CLASS');
        break;
    }
    $this->responseText['priceTotal'] = $this->prefix;
    $this->responseText['preDiscPriceTotalText'] = $this->preDiscPrefix;
    
    $product_check = $db->Execute("SELECT products_tax_class_id FROM " . TABLE_PRODUCTS . " WHERE products_id = " . (int)$_POST['products_id'] . " LIMIT 1");
    if (DPU_SHOW_CURRENCY_SYMBOLS == 'false') {
      $decimal_places = $currencies->get_decimal_places($_SESSION['currency']);
      $decimal_point = $currencies->currencies[$_SESSION['currency']]['decimal_point'];
      $thousands_point = $currencies->currencies[$_SESSION['currency']]['thousands_point'];
      /* use of number_format is governed by the instruction from the php manual: 
       *  http://php.net/manual/en/function.number-format.php
       * By providing below all four values, they will be assigned/used as provided above.
       *  At time of this comment, if only one parameter is used below (remove/comment out the comma to the end of $thousands_point)
       *   then just the number will come back with a comma used at every thousands group (ie. 1,000).  
       *  With the first two parameters provided, a comma will be used at every thousands group and a decimal (.) for every part of the whole number.
       *  The only other option to use this function is to provide all four parameters with the third and fourth parameters identifying the
       *   decimal point and thousands group separater, respectively.
      */
      $this->responseText['priceTotal'] .= number_format($this->shoppingCart->total, $decimal_places, $decimal_point, $thousands_point);
      $this->responseText['preDiscPriceTotal'] = number_format(zen_add_tax($this->shoppingCart->total_before_discounts, zen_get_tax_rate($product_check->fields['products_tax_class_id'])), $decimal_places, $decimal_point, $thousands_point);
    } else {
      if (defined('DISPLAY_PRICE_WITH_TAX') && (DISPLAY_PRICE_WITH_TAX !== 'true' && DISPLAY_PRICE_WITH_TAX !== 'false') && function_exists('get_products_display_price')) {
        
        $display_special_price = false;
        $display_sale_price = zen_get_products_special_price((int)$_POST['products_id'], false);
        
        if ($display_sale_price !== false) {
          $display_special_price = zen_get_products_special_price((int)$_POST['products_id'], true);
        }
        
        $product_data = array(
                          'prices' => array(
                                        'normal_price' => ($display_sale_price && $display_sale_price != $display_special_price || $display_special_price) ? $this->shoppingCart->total/*_before_discounts*/ : $this->shoppingCart->total,
                                        'special_price' => ($display_special_price && $display_sale_price == $display_special_price) ? $this->shoppingCart->total_before_discounts : false, //$this->shoppingCart->total_before_discounts,
                                        'sale_price' => ($display_sale_price && $display_sale_price != $display_special_price) ? $this->shoppingCart->total : false, //$this->shoppingCart->total_before_discounts,
                                      ),
                        );
        
        $product_check = $db->Execute("SELECT products_tax_class_id,
                                              " /*products_price,
                                              products_priced_by_attribute,*/ ."
                                              product_is_free,
                                              product_is_call,
                                              products_type
                                              FROM " . TABLE_PRODUCTS . " 
                                              WHERE products_id=" . (int)$_POST['products_id'] . " LIMIT 1");
        
        $product_data = array_merge($product_data, $product_check->fields);
        
        $this->responseText['priceTotal'] /*.*/= $this->inc_exclude_price_total($product_data) /*. ' : ' . print_r($product_data, true)*/;
//        $this->responseText['preDiscPriceTotal'] .= 'TBD';
        $this->responseText['preDiscPriceTotal'] .= '';/* $this->inc_exclude_price_total($product_data) . ' : ' . print_r($product_data, true);*/
      } else {
        $this->responseText['priceTotal'] .= $currencies->display_price($this->shoppingCart->total, 0 /*zen_get_tax_rate($product_check->fields['products_tax_class_id'])*//* 0 */ /* DISPLAY_PRICE_WITH_TAX */);
        $this->responseText['preDiscPriceTotal'] = $currencies->display_price($this->shoppingCart->total_before_discounts, zen_get_tax_rate($product_check->fields['products_tax_class_id']));
      }
    }

    if (!defined('DPU_OUT_OF_STOCK_IMAGE')) {
      define('DPU_OUT_OF_STOCK_IMAGE', '%s');
    }

    $out_of_stock_image = '';
    $out_of_stock = false;
    if ((STOCK_CHECK == 'true') && (STOCK_ALLOW_CHECKOUT != 'true')) {
      $out_of_stock = true;
    }

    $this->responseText['stock_quantity'] = $this->product_stock . sprintf(DPU_TEXT_PRODUCT_QUANTITY, (abs($this->product_stock) == 1 ? DPU_TEXT_PRODUCT_QUANTITY_SINGLE: DPU_TEXT_PRODUCT_QUANTITY_MULTIPLE));

    switch (true) {
      case ($this->product_stock > 0): // No consideration made yet on allowing quantity to go less than 0.
//        $this->responseText['stock_quantity'] = $this->product_stock;
        break;
      case (false):
        $out_of_stock = false;
        if ((STOCK_CHECK == 'true') && (STOCK_ALLOW_CHECKOUT != 'true')) {
          $out_of_stock = true;
        }
      case ($out_of_stock && $this->num_options == $this->unused && !empty($this->new_temp_attributes)):
        // No selections made yet, stock is 0 or less and not allowed to checkout.
        $out_of_stock_image = sprintf(DPU_OUT_OF_STOCK_IMAGE, zen_image_button(BUTTON_IMAGE_SOLD_OUT_SMALL, BUTTON_SOLD_OUT_SMALL_ALT));
        break;
      case ($out_of_stock && ($this->num_options > $this->unused) && !empty($this->unused)):
        // Not all selections have been made, stock is 0 or less and not allowed to checkout.
        $out_of_stock_image = sprintf(DPU_OUT_OF_STOCK_IMAGE, zen_image_button(BUTTON_IMAGE_SOLD_OUT_SMALL, BUTTON_SOLD_OUT_SMALL_ALT));
        break;
      default:
        // Selections are complete and stock is 0 or less.
        $out_of_stock_image = sprintf(DPU_OUT_OF_STOCK_IMAGE, zen_image_button(BUTTON_IMAGE_SOLD_OUT_SMALL, BUTTON_SOLD_OUT_SMALL_ALT));
        break;
    }

    if ($out_of_stock) {
      if (DPU_SHOW_OUT_OF_STOCK_IMAGE === 'quantity_replace') {
        $this->responseText['stock_quantity'] = $out_of_stock_image;
      } else if (DPU_SHOW_OUT_OF_STOCK_IMAGE === 'after') {
        $this->responseText['stock_quantity'] .= '&nbsp;' . $out_of_stock_image;
      } else if (DPU_SHOW_OUT_OF_STOCK_IMAGE === 'before') {
        $this->responseText['stock_quantity'] = $out_of_stock_image . "&nbsp;" . $this->responseText['stock_quantity'];
      } else if (DPU_SHOW_OUT_OF_STOCK_IMAGE === 'price_replace_only') {
        $this->responseText['priceTotal'] = $out_of_stock_image . "&nbsp;" . $this->responseText['stock_quantity'];;
        $this->responseText['preDiscPriceTotal'] = $out_of_stock_image . "&nbsp;" . $this->responseText['stock_quantity'];;
      }
    }

    
    $this->responseText['weight'] = (string)$this->shoppingCart->weight;
    if (DPU_SHOW_QUANTITY == 'true') {
      foreach ($this->shoppingCart->contents as $key => $value) {
        if (array_key_exists($key, $_SESSION['cart']->contents) && $_SESSION['cart']->contents[$key]['qty'] > 0) { // Hides quantity if the selected variant/options are not in the existing cart.
          $this->responseText['quantity'] = sprintf(DPU_SHOW_QUANTITY_FRAME, convertToFloat($_SESSION['cart']->contents[$key]['qty']));
        }
      }
    }
  }

  protected function inc_exclude_price_total($product_data = array()/*, $tax_class_id = 0, $prices = array(), $product_data = array()*/) {
    global $currencies;
    
    if (DISPLAY_PRICE_WITH_TAX=='inc/ex') {
      $inc_ex_class = 'productTaxExPrice';
    } else if (DISPLAY_PRICE_WITH_TAX=='ex/inc') {
      $inc_ex_class = 'productTaxPrice';
    }
/*
Results sought with inc/ex:

<div class="prodprice" style="display:inline-block;float:none !important;">
    <span class="price_amount" id="productPrices">
      <strong>Price: </strong>
      €531.19
      <span class="productTaxIncTag">&nbsp;Inc VAT</span><br>
      <span class="productTaxExPrice">€439.00</span>
      <span class="productTaxExTag">&nbsp;Ex VAT</span>
    </span> 



    <!--eof Product Name-->
    <span class="NewExVAT"></span>
</div>


*/

/*  Current results:
<div class="prodprice" style="display:inline-block;float:none !important;">
    <span class="price_amount" id="productPrices">
      Price: €531.19
      <span class="productTaxIncTag">&nbsp;Inc VAT</span><br>
      <span class="productTaxExPrice">€439.00</span>
      <span class="productTaxExTag">&nbsp;Ex VAT</span>
      </span> 



<!--eof Product Name-->
<span class="NewExVAT"></span></div>
*/


    // consider discount price for comparisons.
    $show_special_price='';
    $show_sale_price='';
    $free_tag = '';
    $show_display_price = '';
    $discount_amount = 0;
    $display_normal_price = isset($product_data['prices']['normal_price']) ? $product_data['prices']['normal_price'] : 0;
    $display_special_price = isset($product_data['prices']['special_price']) ? $product_data['prices']['special_price'] : 0;
    $display_sale_price = isset($product_data['prices']['sale_price']) ? $product_data['prices']['sale_price'] : 0;
    $tax_class_id = $product_data['products_tax_class_id'];
    $tax_rate = zen_get_tax_rate($tax_class_id);

//    $display_normal_price = $prices['normal_price']; //zen_get_products_base_price($products_id);
//    $display_special_price = $prices['special_price']; //zen_get_products_special_price($products_id, true);
//    $display_sale_price = $prices['sale_price']; //zen_get_products_special_price($products_id, false);
    $discount_price = ($display_sale_price != 0 ? $display_sale_price : $display_special_price);
    
    if (SHOW_SALE_DISCOUNT_STATUS == '1' && ($display_special_price != 0 || $display_sale_price != 0)) {
      if (SHOW_SALE_DISCOUNT == '1') {
        if ($display_normal_price != 0) {
          $discount_amount = ($display_normal_price != 0 ? (100 - (($discount_price/$display_normal_price) * 100)) : 0);
        } else {
          $discount_amount = $display_normal_price - $discount_price;
        }
      }
    }

    if ($discount_amount) {
      if (SHOW_SALE_DISCOUNT == 1) {
        $show_sale_discount = '<span class="productPriceDiscount">' . '<br />1' . PRODUCT_PRICE_DISCOUNT_PREFIX . number_format($discount_amount, SHOW_SALE_DISCOUNT_DECIMALS) . PRODUCT_PRICE_DISCOUNT_PERCENTAGE . '</span>';
      } else {
        $show_sale_discount = '<span class="productPriceDiscount">' . '<br />2' . PRODUCT_PRICE_DISCOUNT_PREFIX . display_price($discount_amount, $tax_rate) . PRODUCT_PRICE_DISCOUNT_AMOUNT . '</span>';
      }
    }
    $added_text = '';
    if ($display_special_price) {
//      $added_text .= ' in display special price ';
      $show_sale_price='';
      $show_normal_price = '<span class="normalprice">3'.display_price($display_normal_price, $tax_rate).' ';
      $show_price_inc_ex_tax = '<br />'.'<span class="'.$inc_ex_class.'">4'.display_price_inc_ex_tax($display_special_price, $tax_rate).'</span>'; // !!! AGM
      if ($display_sale_price && $display_sale_price != $display_special_price) {
        $show_special_price = '&nbsp;'.'<span class="productSpecialPriceSale">5'.display_price($display_special_price, $tax_rate).'</span>';
        if ($product_data['product_is_free']=='1') {
          $show_sale_price = '<br />66'.'<span class="productSalePrice">6'.PRODUCT_PRICE_SALE.'<s>'.display_price($display_sale_price, $tax_rate).'</s>'.'</span>';
        } else {
          $show_sale_price = '<br />'.'<span class="productSalePrice">7'.PRODUCT_PRICE_SALE.display_price($display_sale_price, $tax_rate).'</span>';
        }
      } else {
        $show_special_price =  '<br />' . ($product_data['product_is_free'] == '1' ? 'true' : 'false');
        $show_special_price .= '<span class="productSpecialPrice">8';
        if ($product_data['product_is_free'] == '1') {
          $show_special_price .= '9<s>';
        }
        $show_special_price .= display_price($display_special_price, $tax_rate);
        if ($product_data['product_is_free'] == '1') {
          $show_special_price .= '</s>';
        }
        $show_special_price .= '</span>';
      }
    } else {
//      $added_text .= ' in NOT display special price ';
      $show_special_price='';
      if ($display_sale_price) {
//      $added_text .= ' in display sale price ';
        $show_normal_price = '<span class="normalprice">10'.display_price($display_normal_price, $tax_rate).' </span>';
        $show_sale_price = '<br />'.'<span class="productSalePrice">11'.PRODUCT_PRICE_SALE.display_price($display_sale_price, $tax_rate).'</span>';
        $display_price = $display_sale_price;
	    } else {
//      $added_text .= ' in NOT display sale price ';
        $show_sale_price='';
        
        if ($product_data['product_is_free'] == '1') {
         $show_normal_price .= '<s>';
        }
        
    $show_normal_price = display_price($display_normal_price, $tax_rate);
        
        if ($product_check->fields['product_is_free'] == '1') {
          $show_normal_price .='</s>';
        }
        $display_price = $display_normal_price;
//    $tax_rate = 0;
      }
//    if (DISPLAY_PRICE_WITH_TAX=='inc/ex') {
//      $tax_rate = zen_get_tax_rate($tax_class_id);
//    }
    }
    $show_price_inc_ex_tax = '<br />'.'<span class="'.$inc_ex_class.'">' . display_price_inc_ex_tax($display_price, $tax_rate) . '</span>';




    $final_display_price = '';
    $final_display_price_inc_ex_tax = '';
    $final_display_ex_tag = '';
    $final_display_inc_tag = '';
    
    if ($tax_rate == 0) {
      $final_display_price = $show_normal_price.' '.PRICE_ZERO_TAX_TEXT;
    } else if ($display_normal_price != 0) { // don't show the $0.00
      $final_display_price=$show_normal_price;
      $final_display_price_inc_ex_tax=$show_price_inc_ex_tax;
      $final_display_ex_tag='<span class="productTaxExTag">&nbsp;'.PRICE_EX_TAX_TEXT.'</span>';
      $final_display_inc_tag='<span class="productTaxIncTag">&nbsp;'.PRICE_INC_TAX_TEXT.'</span>';
    }
    
    $extra_inner_text = '';
    $extra_outer_text = '';
    
    switch (DISPLAY_PRICE_WITH_TAX) {
      case 'ex/inc':
        $extra_inner_text = $final_display_ex_tag;
        $extra_outer_text = $final_display_inc_tag;
      break;
      
      case 'inc/ex':
        $extra_inner_text = $final_display_inc_tag;
        $extra_outer_text = $final_display_ex_tag;
      break;
      
      default:
        check_inc_ex_status();
        $final_display_price_inc_ex_tax = '';
        if(isset($_SESSION["inc_ex_tax"])) {
          $extra_inner_text = $final_display_ex_tag;
        
          if ($_SESSION["inc_ex_tax"]) {
            $extra_inner_text = $final_display_inc_tag;
          }
        }
      break;
    }
    
    $final_display_price = $final_display_price . $show_special_price . $show_sale_price . $extra_inner_text . $show_sale_discount . $final_display_price_inc_ex_tax . $extra_outer_text;
    
    // If Free, Show it
    if ($product_data['product_is_free'] == '1') {
      $free_tag = '<br />';
      if (OTHER_IMAGE_PRICE_IS_FREE_ON == '0') {
        $free_tag .= PRODUCTS_PRICE_IS_FREE_TEXT . ' yup free';
      } else {
        $free_tag .= zen_image(DIR_WS_TEMPLATE_IMAGES . OTHER_IMAGE_PRICE_IS_FREE, PRODUCTS_PRICE_IS_FREE_TEXT);
      }
    }
    
    // If Call for Price, Show it
    if ($product_data['product_is_call']) {
      $call_tag = '<br />';
      if (PRODUCTS_PRICE_IS_CALL_IMAGE_ON == '0') {
        $call_tag .= PRODUCTS_PRICE_IS_CALL_FOR_PRICE_TEXT;
      } else {
        $call_tag .= zen_image(DIR_WS_TEMPLATE_IMAGES . OTHER_IMAGE_CALL_FOR_PRICE, PRODUCTS_PRICE_IS_CALL_FOR_PRICE_TEXT);
      }
    }

    return $final_display_price.$free_tag.$call_tag . '</span>:'.$added_text;




    $result = $inc_ex_class;
    $result = $show_price_inc_ex_tax;
    
    return $result;
  }

  /*
   * Removes attributes that were added to help calculate the total price in absence of attributes having a default selection
   *   and the product being priced by attributes.
   */
  protected function removeExtraSelections() {
    if (!empty($this->new_attributes)) {
    foreach ($this->shoppingCart->contents as $products_id => $cart_contents) {
      // If there were attributes that were added to support calculating
      //   the further additional minimum price.  Removing it will restore
      //   the cart to the data collected directly from the page.
      if (array_key_exists($products_id, $this->new_attributes) && is_array($this->new_attributes[$products_id])) {

        foreach ($this->new_attributes[$products_id] as $option => $value) {
          //CLR 020606 check if input was from text box.  If so, store additional attribute information
          //CLR 020708 check if text input is blank, if so do not add to attribute lists
          //CLR 030228 add htmlspecialchars processing.  This handles quotes and other special chars in the user input.
          $attr_value = NULL;
          $blank_value = FALSE;
          if (strstr($option, TEXT_PREFIX)) {
            if (trim($value) == NULL) {
              $blank_value = TRUE;
            } else {
              $option = substr($option, strlen(TEXT_PREFIX));
              $attr_value = stripslashes($value);
              $value = PRODUCTS_OPTIONS_VALUES_TEXT_ID;
              unset($this->shoppingCart->contents[$products_id]['attributes_values'][$option]);// = $attr_value;
            }
          }

          if (!$blank_value) {
            if (is_array($value)) {
              foreach ($value as $opt => $val) {
                unset($this->shoppingCart->contents[$products_id]['attributes'][$option . '_chk' . $val]); // = $val;
              }
            } else {
              unset($this->shoppingCart->contents[$products_id]['attributes'][$option]); // = $value;
            }
          }
        } // EOF foreach of the new_attributes
      } // EOF if $this->new_attributes
    } // foreach on cart
    } // if $this->new_attributes
  }

  /**
   * Tests for the need to show all types of prices to be displayed by and of each individual function to display text of a price.
   * @return bool
   */
  protected function attributesDisplayMultiplePrices() {
    
    $response = ($this->attributeDisplayStartAtPrices() && $this->attributeDisplayAtLeastPrices());
    
    return $response;
  }
  
  /**
   * Helper function to test for the need to show Start At price text.
   * @return bool
   */
  protected function attributeDisplayStartAtPrices() {
    
    $response = ($this->priceDisplay === 'start_at_least' || $this->priceDisplay === 'start_at');
    
    return $response;
  }
  
  /**
   * Helper function to test for the need to show At Least price text.
   * @return bool
   */
  protected function attributeDisplayAtLeastPrices() {
    
    $response = ($this->priceDisplay === 'start_at_least' || $this->priceDisplay === 'at_least');
    
    return $response;
  }
  
  /*
   * Inserts multiple non-attributed products into the shopping cart
   *
   * @return void
   */
  protected function insertProducts() {
    foreach ($_POST['products_id'] as $id => $qty) {
      $this->shoppingCart->contents[] = array((int)$id);
      $this->shoppingCart->contents[(int)$id] = array('qty' => (convertToFloat($qty) <= 0 ? zen_get_buy_now_qty((int)$id) : convertToFloat($qty))); //(float)$qty);
    }

    var_dump($this->shoppingCart);
    die();
  }

  /*
   * Inserts the product into the shoppingCart content array
   *
   * @returns void
   */
  protected function insertProduct() {
    global $db;
//    $this->shoppingCart->contents[$_POST['products_id']] = array('qty' => (float)$_POST['cart_quantity']);
    $attributes = array();
    $this->num_options = 0;
    $this->unused = 0;

    foreach ($_POST as $key => $val) {
      if (is_array($val)) {
        foreach ($val as $k => $v) {
          $attributes[$k] = $v;
        }
      }
    }

    if (!empty($attributes) || zen_has_product_attributes_values($_POST['products_id'])) {
      // If product is priced by attribute then determine which attributes had not been added, 
      //  add them to the attribute list such that product added to the cart is fully defined with the minimum value(s), though 
      //  at the moment seems that similar would be needed even for not priced by attribute possibly... Will see... Maybe someone will report if an issue.

      if(!defined('DPU_PROCESS_ATTRIBUTES')) define('DPU_PROCESS_ATTRIBUTES', 'all');

      $product_check_result = false;
      if (DPU_PROCESS_ATTRIBUTES !== 'all') {
        $product_check = $db->Execute("select products_priced_by_attribute from " . TABLE_PRODUCTS . " where products_id = " . (int)$_POST['products_id']);
        $product_check_result = $product_check->fields['products_priced_by_attribute'] == '1';
      }

      // do not select display only attributes and do select attributes_price_base_included is true
      $this->product_attr_query = "select pa.options_id, pa.options_values_id, pa.attributes_display_only, pa.attributes_price_base_included, po.products_options_type, round(concat(pa.price_prefix, pa.options_values_price), 5) as value from " . TABLE_PRODUCTS_ATTRIBUTES . " pa LEFT JOIN " . TABLE_PRODUCTS_OPTIONS . " po on (po.products_options_id = pa.options_id) where products_id = " . (int)$_POST['products_id'] . " and attributes_display_only != '1' and attributes_price_base_included='1'". " order by pa.options_id, value";
      
      $query_handled = false;
      $GLOBALS['zco_notifier']->notify('DPU_NOTIFY_INSERT_PRODUCT_QUERY', (int)$_POST['products_id'], $query_handled);
      
      $product_att_query = $db->Execute($this->product_attr_query);

// add attributes that are price dependent and in or not in the page's submission
      // Support price determination for product that are modified by attribute's price and are priced by attribute or just modified by the attribute's price.
      $process_price_attributes = (defined('DPU_PROCESS_ATTRIBUTES') && DPU_PROCESS_ATTRIBUTES === 'all') ? true : $product_check_result;
      if ($process_price_attributes && $product_att_query->RecordCount() >= 1) {
        $the_options_id= 'x';
        $new_attributes = array();
//        $this->num_options = 0;
        while (!$product_att_query->EOF) {
//          if ($product_att_query->fields['products_options_type'] !== PRODUCTS_OPTIONS_TYPE_CHECKBOX) { // Do not add possible check box prices as a requirement // mc12345678 17-06-13 Commented out this because attributes included in base price are controlled by attributes controller.  If a check box is not to be included, then its setting should be "off" for base_price.
            if ( $the_options_id != $product_att_query->fields['options_id']) {
              $the_options_id = $product_att_query->fields['options_id'];
              $new_attributes[$the_options_id] = $product_att_query->fields['options_values_id'];
              $this->num_options++;
            } elseif (array_key_exists($the_options_id, $attributes) && $attributes[$the_options_id] == $product_att_query->fields['options_values_id']) {
              $new_attributes[$the_options_id] = $product_att_query->fields['options_values_id'];
            }
//          }
            
          $product_att_query->MoveNext();
        }

        // Need to now resort the attributes as one would have expected them to be presented which is to sort the option name(s)
        if (PRODUCTS_OPTIONS_SORT_ORDER=='0') {
          $options_order_by= ' order by LPAD(popt.products_options_sort_order,11,"0"), popt.products_options_name';
        } else {
          $options_order_by= ' order by popt.products_options_name';
        }

        $sql = "select distinct popt.products_options_id, popt.products_options_name, popt.products_options_sort_order, popt.products_options_type
        from        " . TABLE_PRODUCTS_OPTIONS . " popt, " . TABLE_PRODUCTS_ATTRIBUTES . " patrib
        where           patrib.products_id=" . (int)$_POST['products_id'] . "
        and             patrib.options_id = popt.products_options_id
        and             popt.language_id = " . (int)$_SESSION['languages_id'] . " " .
        $options_order_by;

        $products_options_names = $db->Execute($sql);
        
        $new_temp_attributes = array();
        $this->new_temp_attributes = array();
//        $this->unused = 0;
        //  To appear in the cart, $new_temp_attributes[$options_id]
        // must contain either the selection or the lowest priced selection
        // if an "invalid" selection had been made.
        //  To get removed from the cart for display purposes
        // the $options_id must be added to $this->new_temp_attributes
        while (!$products_options_names->EOF) {
          $options_id = $products_options_names->fields['products_options_id'];
          $options_type = $products_options_names->fields['products_options_type'];

          // Taken from the expected format in includes/modules/attributes.
          switch ($options_type) {
            case (PRODUCTS_OPTIONS_TYPE_TEXT):
              $options_id = TEXT_PREFIX . $options_id;
              break;
            case (PRODUCTS_OPTIONS_TYPE_FILE):
              $options_id = TEXT_PREFIX . $options_id;
              break;
            default:
              $this->notify('NOTIFY_DYNAMIC_PRICE_UPDATER_DEFAULT_INSERT_PRODUCT_TYPE', $options_type, $options_id);
              break;
          }

          $this->display_only_value = isset($attributes[$options_id]) ? !zen_get_attributes_valid($_POST['products_id'], $options_id, $attributes[$options_id]) : true;

          if (isset($attributes[$options_id]) && $attributes[$options_id] === 0 && (function_exists('zen_option_name_base_expects_no_values') ? !zen_option_name_base_expects_no_values($options_id) : !$this->zen_option_name_base_expects_no_values($options_id))) $this->display_only_value = true;
          
          $this->notify('NOTIFY_DYNAMIC_PRICE_UPDATER_DISPLAY_ONLY');

          if (array_key_exists($options_id, $attributes) && !$this->display_only_value) {
            // If the options_id selected is a valid attribute then add it to be part of the calculation
            $new_temp_attributes[$options_id] = $attributes[$options_id];
          } elseif (array_key_exists($options_id, $attributes) && $this->display_only_value) {
            // If the options_id selected is not a valid attribute, then add a valid attribute determined above and mark it
            //   to be deleted from the shopping cart after the price has been determined.
            $this->new_temp_attributes[$options_id] = $attributes[$options_id];
            $new_temp_attributes[$options_id] = $new_attributes[$options_id];
            $this->unused++;
          } elseif (array_key_exists($options_id, $new_attributes)) {
            // if it is not already in the $attributes, then it is something that needs to be added for "removal"
            //   and by adding it, makes the software consider how many files need to be edited.
            $this->new_temp_attributes[$options_id] = $new_attributes[$options_id];
            $new_temp_attributes[$options_id] = false; //$new_attributes[$options_id];
            $this->unused++;
          }
          /*elseif (array_key_exists($options_id, $attributes) && array_key_exists($options_id, $new_attributes) && !zen_get_attributes_valid($_POST['products_id'], $options_id, $attributes[$options_id])) {
          } elseif (array_key_exists($options_id, $new_attributes)) {
            // If the option_id has not been selected but is one that is to be populated, then add it to the cart and mark it
            //   to be deleted from the shopping cart after the price has been determined.
            $this->new_temp_attributes[$options_id] = $new_attributes[$options_id];
            $new_temp_attributes[$options_id] = $new_attributes[$options_id];
            $this->unused++;
          }*/
            
          $products_options_names->MoveNext();
        }

        $attributes = $new_temp_attributes;
      }

      $products_id = zen_get_uprid((int)$_POST['products_id'], $attributes);
      
      $this->product_stock = zen_get_products_stock($_POST['products_id'], $attributes);
      
      $this->new_attributes[$products_id] = $this->new_temp_attributes;
      $cart_quantity = !empty($_POST['cart_quantity']) ? $_POST['cart_quantity'] : 0;
      $this->shoppingCart->contents[$products_id] = array('qty' => ((convertToFloat($cart_quantity) <= 0) ? zen_get_buy_now_qty($products_id) : convertToFloat($cart_quantity)),
                                                          );

      foreach ($attributes as $option => $value) {
        //CLR 020606 check if input was from text box.  If so, store additional attribute information
        //CLR 020708 check if text input is blank, if so do not add to attribute lists
        //CLR 030228 add htmlspecialchars processing.  This handles quotes and other special chars in the user input.
        $attr_value = NULL;
        $blank_value = FALSE;
        if (strstr($option, TEXT_PREFIX)) {
          if (trim($value) == NULL) {
            $blank_value = TRUE;
          } else {
            $option = substr($option, strlen(TEXT_PREFIX));
            $attr_value = stripslashes($value);
            $value = PRODUCTS_OPTIONS_VALUES_TEXT_ID;
//            $product_info['attributes_values'][$option] = $attr_value;

            // -----
            // Check that the length of this TEXT attribute is less than or equal to its "Max Length" definition. While there
            // is some javascript on a product details' page that limits the number of characters entered, the customer
            // can choose to disable javascript entirely or circumvent that checking by performing a copy&paste action.
            // Disabling javascript would have also disabled operation of this plugin so primarily by copy&paste.
            //
            $check = $db->Execute ("SELECT products_options_length FROM " . TABLE_PRODUCTS_OPTIONS . " WHERE products_options_id = " . (int)$option . " LIMIT 1");
            if (!$check->EOF) {
              if (strlen ($attr_value) > $check->fields['products_options_length']) {
                $attr_value = zen_trunc_string ($attr_value, $check->fields['products_options_length'], '');
              }
              $this->shoppingCart->contents[$products_id]['attributes_values'][$option] = $attr_value;
            }
          }
        }

        if (!$blank_value && $value !== false) {
          if (is_array($value)) {
            foreach ($value as $opt => $val) {
//              $product_info['attributes'][$option . '_chk' . $val] = $val;
              $this->shoppingCart->contents[$products_id]['attributes'][$option . '_chk' . $val] = $val;
            }
          } else {
//            $product_info['attributes'][$option] = $value;
            $this->shoppingCart->contents[$products_id]['attributes'][$option] = $value;
          }
        }
      }
    } else {
      $products_id = (int)$_POST['products_id'];
      $this->product_stock = zen_get_products_stock($products_id);
      $cart_quantity = !empty($_POST['cart_quantity']) ? $_POST['cart_quantity'] : 0;
      $this->shoppingCart->contents[$products_id] = array('qty' => (convertToFloat($cart_quantity) <= 0 ? zen_get_buy_now_qty($products_id) : convertToFloat($cart_quantity)));
    }
  }

  /*
   * Identifies the option name id(s) that affect price.
   *
   */
  public function getOptionPricedIds($products_id) {

    // Identify the attribute information associated with the provided $products_id.
    $attribute_price_query = "SELECT *
                                FROM " . TABLE_PRODUCTS_ATTRIBUTES . "
                                WHERE products_id = " . (int)$products_id . "
                                ORDER BY options_id, options_values_price";

    $attribute_price = $GLOBALS['db']->Execute($attribute_price_query);
    
    $last_id = 'X';
    $options_id = array();
    
    // Populate $options_id to contain the options_ids that potentially affect price.
    while (!$attribute_price->EOF) {
      // Basically if the options_id has already been captured, then don't try to process again.
      if ($last_id == $attribute_price->fields['options_id']) {
        $attribute_price->MoveNext();
        continue;
      }
      
      /* Capture the options_id of option names that could affect price
      
      Identify an option name that could affect price by:
          having a price that is not zero,
          having quantity prices (though this is not (yet) deconstruct the prices and existing quantity),
          having a price factor that could affect the price,
          is a text field that has a word or letter price.
      */
      if (!(
            $attribute_price->fields['options_values_price'] == 0 && 
            !zen_not_null($attribute_price->fields['attributes_qty_prices']) &&
            !zen_not_null($attribute_price->fields['attributes_qty_prices_onetime']) &&
            $attribute_price->fields['attributes_price_onetime'] == 0 &&
            (
              $attribute_price->fields['attributes_price_factor'] ==
              $attribute_price->fields['attributes_price_factor_offset'] 
            ) &&
            (
              $attribute_price->fields['attributes_price_factor_onetime'] ==
              $attribute_price->fields['attributes_price_factor_onetime_offset']
            )
           ) 
            ||
            (
              zen_get_attributes_type($attribute_price->fields['products_attributes_id']) == PRODUCTS_OPTIONS_TYPE_TEXT &&
              !($attribute_price->fields['attributes_price_words'] == 0 &&
              $attribute_price->fields['attributes_price_letters'] == 0)
            )
          ) {

        $prefix_format = 'id[:option_id:]';
        
        $attribute_type = zen_get_attributes_type($attribute_price->fields['products_attributes_id']);
        
        switch ($attribute_type) {
          case (PRODUCTS_OPTIONS_TYPE_TEXT):
            $prefix_format = $GLOBALS['db']->bindVars($prefix_format, ':option_id:', TEXT_PREFIX . ':option_id:', 'noquotestring');
            break;
          case (PRODUCTS_OPTIONS_TYPE_FILE):
            $prefix_format = $GLOBALS['db']->bindVars($prefix_format, ':option_id:', TEXT_PREFIX . ':option_id:', 'noquotestring');
            break;
          default:
            $GLOBALS['zco_notifier']->notify('NOTIFY_DYNAMIC_PRICE_UPDATER_ATTRIBUTE_ID_TEXT', $attribute_price->fields, $prefix_format, $options_id, $last_id); 
        }
        
        $result = $GLOBALS['db']->bindVars($prefix_format, ':option_id:', $attribute_price->fields['options_id'], 'integer');
        $options_id[$attribute_price->fields['options_id']] = $result;
        $last_id = $attribute_price->fields['options_id'];

        $attribute_price->MoveNext();
        continue;
      }
      
      $attribute_price->MoveNext();
    }
    
    return $options_id;
  }
  
  /*
   * Prepares the output for the Updater's sidebox display
   *
   */
  protected function getSideboxContent() {
    global $currencies, $db;

/*    $product_check = $db->Execute("SELECT products_tax_class_id FROM " . TABLE_PRODUCTS . " WHERE products_id = '" . (int)$_POST['products_id'] . "'" . " LIMIT 1");
    $product = $db->Execute("SELECT products_id, products_price, products_tax_class_id, products_weight,
                      products_priced_by_attribute, product_is_always_free_shipping, products_discount_type, products_discount_type_from,
                      products_virtual, products_model
                      FROM " . TABLE_PRODUCTS . "
                      WHERE products_id = '" . (int)$_POST['products_id'] . "'");

    $prid = $product->fields['products_id'];
    $products_tax = zen_get_tax_rate(0);
    $products_price = $product->fields['products_price'];
    $qty = (float)$_POST['cart_quantity'];*/
    $out = array();
    $global_total = 0;
    //$products = array(); // Unnecessary define
    $products = $this->shoppingCart->get_products();
    for ($i=0, $n=count($products); $i<$n; $i++) 
    {

      $product_check = $db->Execute("SELECT products_tax_class_id FROM " . TABLE_PRODUCTS . " WHERE products_id = " . (int)$products[$i]['id'] . " LIMIT 1");
      $product = $db->Execute("SELECT products_id, products_price, products_tax_class_id, products_weight,
                        products_priced_by_attribute, product_is_always_free_shipping, products_discount_type, products_discount_type_from,
                        products_virtual, products_model
                        FROM " . TABLE_PRODUCTS . "
                        WHERE products_id = " . (int)$products[$i]['id']);

      $prid = $product->fields['products_id'];
      $products_tax = zen_get_tax_rate(0);
      $products_price = $product->fields['products_price'];
      $qty = convertToFloat($products[$i]['quantity']);



      if (isset($this->shoppingCart->contents[$products[$i]['id']]['attributes']) && is_array($this->shoppingCart->contents[$products[$i]['id']]['attributes'])) {
//    while (isset($this->shoppingCart->contents[$_POST['products_id']]['attributes']) && list($option, $value) = each($this->shoppingCart->contents[$_POST['products_id']]['attributes'])) {
        foreach ($this->shoppingCart->contents[$products[$i]['id']]['attributes'] as $option => $value) {
          // $adjust_downloads ++; // not used? mc12345678 18-05-05

          $attribute_price = $db->Execute("SELECT *
                                    FROM " . TABLE_PRODUCTS_ATTRIBUTES . "
                                    WHERE products_id = " . (int)$prid . "
                                    AND options_id = " . (int)$option . "
                                    AND options_values_id = " . (int)$value);

          if ($attribute_price->EOF) continue;

          $data = $db->Execute("SELECT products_options_values_name
                         FROM " . TABLE_PRODUCTS_OPTIONS_VALUES . "
                         WHERE products_options_values_id = " . (int)$value);
          $name = $data->fields['products_options_values_name'];

          $new_attributes_price = 0;
          $discount_type_id = '';
          $sale_maker_discount = '';
          $total = 0;

          if ($attribute_price->fields['product_attribute_is_free'] == '1' and zen_get_products_price_is_free((int)$prid)) {
            // no charge for attribute
          } else {
            // + or blank adds
            if ($attribute_price->fields['price_prefix'] == '-') {
              // appears to confuse products priced by attributes
              if ($product->fields['product_is_always_free_shipping'] == '1' or $product->fields['products_virtual'] == '1') {
                $shipping_attributes_price = zen_get_discount_calc($product->fields['products_id'], $attribute_price->fields['products_attributes_id'], $attribute_price->fields['options_values_price'], $qty);
                $this->free_shipping_price -= $qty * zen_add_tax(($shipping_attributes_price), $products_tax);
              }
              if ($attribute_price->fields['attributes_discounted'] == '1') {
                // calculate proper discount for attributes
                $new_attributes_price = zen_get_discount_calc($product->fields['products_id'], $attribute_price->fields['products_attributes_id'], $attribute_price->fields['options_values_price'], $qty);
                $total -= $qty * zen_add_tax(($new_attributes_price), $products_tax);
              } else {
                $total -= $qty * zen_add_tax($attribute_price->fields['options_values_price'], $products_tax);
              }
              $total = $total;
            } else {
              // appears to confuse products priced by attributes
              if ($product->fields['product_is_always_free_shipping'] == '1' or $product->fields['products_virtual'] == '1') {
                $shipping_attributes_price = zen_get_discount_calc($product->fields['products_id'], $attribute_price->fields['products_attributes_id'], $attribute_price->fields['options_values_price'], $qty);
                $this->free_shipping_price += $qty * zen_add_tax(($shipping_attributes_price), $products_tax);
              }
              if ($attribute_price->fields['attributes_discounted'] == '1') {
                // calculate proper discount for attributes
                $new_attributes_price = zen_get_discount_calc($product->fields['products_id'], $attribute_price->fields['products_attributes_id'], $attribute_price->fields['options_values_price'], $qty);
                $total += $qty * zen_add_tax(($new_attributes_price), $products_tax);
                // echo $product->fields['products_id'].' - '.$attribute_price->fields['products_attributes_id'].' - '. $attribute_price->fields['options_values_price'].' - '.$qty."\n";
              } else {
                $total += $qty * zen_add_tax($attribute_price->fields['options_values_price'], $products_tax);
              }
            }
          }
          $global_total += $total;
          $cart_quantity = !empty($_POST['cart_quantity']) ? $_POST['cart_quantity'] : 0;
          $qty2 = sprintf('<span class="DPUSideboxQuantity">' . DPU_SIDEBOX_QUANTITY_FRAME . '</span>', convertToFloat($cart_quantity));
          if (defined('DPU_SHOW_SIDEBOX_CURRENCY_SYMBOLS') && DPU_SHOW_SIDEBOX_CURRENCY_SYMBOLS == 'false') {
            $decimal_places = $currencies->get_decimal_places($_SESSION['currency']);
            $decimal_point = $currencies->currencies[$_SESSION['currency']]['decimal_point'];
            $thousands_point = $currencies->currencies[$_SESSION['currency']]['thousands_point'];
            /* use of number_format is governed by the instruction from the php manual: 
            *  http://php.net/manual/en/function.number-format.php
            * By providing below all four values, they will be assigned/used as provided above.
            *  At time of this comment, if only one parameter is used below (remove/comment out the comma to the end of $thousands_point)
            *   then just the number will come back with a comma used at every thousands group (ie. 1,000).  
             *  With the first two parameters provided, a comma will be used at every thousands group and a decimal (.) for every part of the whole number.
             *  The only other option to use this function is to provide all four parameters with the third and fourth parameters identifying the
            *   decimal point and thousands group separater, respectively.
            */
            $total = sprintf(DPU_SIDEBOX_PRICE_FRAME, number_format($this->shoppingCart->total, $decimal_places, $decimal_point, $thousands_point));
          } else {
            $total = sprintf(DPU_SIDEBOX_PRICE_FRAME, $currencies->display_price($total, 0 /* ?? Should this tax be applied? zen_get_tax_rate($product_check->fields['products_tax_class_id'])*/));
          }
          $out[] = sprintf(DPU_SIDEBOX_FRAME, $name, $total, $qty2);
        }
      }
    } // EOF FOR loop of product

    if (defined('DPU_SHOW_SIDEBOX_CURRENCY_SYMBOLS') && DPU_SHOW_SIDEBOX_CURRENCY_SYMBOLS == 'false') {
      $decimal_places = $currencies->get_decimal_places($_SESSION['currency']);
      $decimal_point = $currencies->currencies[$_SESSION['currency']]['decimal_point'];
      $thousands_point = $currencies->currencies[$_SESSION['currency']]['thousands_point'];
      /* use of number_format is governed by the instruction from the php manual: 
      *  http://php.net/manual/en/function.number-format.php
      * By providing below all four values, they will be assigned/used as provided above.
      *  At time of this comment, if only one parameter is used below (remove/comment out the comma to the end of $thousands_point)
      *   then just the number will come back with a comma used at every thousands group (ie. 1,000).  
       *  With the first two parameters provided, a comma will be used at every thousands group and a decimal (.) for every part of the whole number.
       *  The only other option to use this function is to provide all four parameters with the third and fourth parameters identifying the
      *   decimal point and thousands group separater, respectively.
      */
      $out[] = sprintf('<hr />' . DPU_SIDEBOX_TOTAL_FRAME, number_format($this->shoppingCart->total, $decimal_places, $decimal_point, $thousands_point));
    } else {
      $out[] = sprintf('<hr />' . DPU_SIDEBOX_TOTAL_FRAME, $currencies->display_price($this->shoppingCart->total, 0));
    }

    $cart_quantity = !empty($_POST['cart_quantity']) ? $_POST['cart_quantity'] : 0;
    $qty2 = sprintf('<span class="DPUSideboxQuantity">' . DPU_SIDEBOX_QUANTITY_FRAME . '</span>', convertToFloat($cart_quantity));
    $total = sprintf(DPU_SIDEBOX_PRICE_FRAME, $currencies->display_price($this->shoppingCart->total - $global_total, 0));
    array_unshift($out, sprintf(DPU_SIDEBOX_FRAME, DPU_BASE_PRICE, $total, $qty2));

    $this->responseText['sideboxContent'] = implode('', $out);
  }

  function setCurrentPage() {
    global $db, $request_type;

    if (isset($_SESSION['customer_id']) && $_SESSION['customer_id']) {
      $wo_customer_id = $_SESSION['customer_id'];

      $customer_query = "select customers_firstname, customers_lastname
                           from " . TABLE_CUSTOMERS . "
                           where customers_id = '" . (int)$_SESSION['customer_id'] . "'";

      $customer = $db->Execute($customer_query);

      $wo_full_name = $customer->fields['customers_lastname'] . ', ' . $customer->fields['customers_firstname'];
    } else {
      $wo_customer_id = '';
      $wo_full_name = '&yen;' . 'Guest';
    }

    $wo_session_id = zen_session_id();
    $wo_ip_address = (isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : 'Unknown');
    $wo_user_agent = substr(zen_db_prepare_input($_SERVER['HTTP_USER_AGENT']), 0, 254);

    $page = zen_get_info_page((int)$_GET['products_id']);
    $uri = zen_href_link($page, zen_get_all_get_params(), $request_type);
    if (substr($uri, -1)=='?') $uri = substr($uri,0,strlen($uri)-1);
    $wo_last_page_url = (zen_not_null($uri) ? substr($uri, 0, 254) : 'Unknown');
    $current_time = time();
    $xx_mins_ago = ($current_time - 900);

    // remove entries that have expired
    $sql = "delete from " . TABLE_WHOS_ONLINE . "
            where time_last_click < '" . $xx_mins_ago . "'";

    $db->Execute($sql);

    $stored_customer_query = "select count(*) as count
                                from " . TABLE_WHOS_ONLINE . "
                                where session_id = '" . zen_db_input($wo_session_id) . "' and ip_address='" . zen_db_input($wo_ip_address) . "'";

    $stored_customer = $db->Execute($stored_customer_query);

    if (empty($wo_session_id)) {
      $wo_full_name = '&yen;' . 'Spider';
    }

    if ($stored_customer->fields['count'] > 0) {
      $sql = "update " . TABLE_WHOS_ONLINE . "
                set customer_id = '" . (int)$wo_customer_id . "',
                    full_name = '" . zen_db_input($wo_full_name) . "',
                    ip_address = '" . zen_db_input($wo_ip_address) . "',
                    time_last_click = '" . zen_db_input($current_time) . "',
                    last_page_url = '" . zen_db_input($wo_last_page_url) . "',
                    host_address = '" . zen_db_input($_SESSION['customers_host_address']) . "',
                    user_agent = '" . zen_db_input($wo_user_agent) . "'
                where session_id = '" . zen_db_input($wo_session_id) . "' and ip_address='" . zen_db_input($wo_ip_address) . "'";

      $db->Execute($sql);

    } else {
      $sql = "insert into " . TABLE_WHOS_ONLINE . "
                  (customer_id, full_name, session_id, ip_address, time_entry,
                   time_last_click, last_page_url, host_address, user_agent)
                values ('" . (int)$wo_customer_id . "', '" . zen_db_input($wo_full_name) . "', '"
                           . zen_db_input($wo_session_id) . "', '" . zen_db_input($wo_ip_address)
                           . "', '" . zen_db_input($current_time) . "', '" . zen_db_input($current_time)
                           . "', '" . zen_db_input($wo_last_page_url)
                           . "', '" . zen_db_input($_SESSION['customers_host_address'])
                           . "', '" . zen_db_input($wo_user_agent)
                           . "')";

      $db->Execute($sql);
    }

  }

  /**
   * DEPRECATED -- Seriously? For the love of all that's normal WHY THROW AN ERROR AGAIN?!?!
   * Performs an error dump
   *
   * @param mixed $errorMsg
   */
  /* function throwError($errorMsg) {
    $this->responseType = 'error';
    $this->responseText[] = $errorMsg;

    $this->dumpOutput();
  } */

  /*
   * Formats the response and flushes with the appropriate headers
   * This should be called last as it issues an exit
   *
   * @return void
   */
  protected function dumpOutput($outputType = "XML") {
    if ($outputType == "XML") {
    // output the header for XML
    header("content-type: text/xml");
    // set the XML file DOCTYPE
    echo '<?xml version="1.0" encoding="UTF-8" ?>' . "\n";
    // set the responseType
    echo '<root>' . "\n" . '<responseType>' . $this->responseType . '</responseType>' . "\n";
    // now loop through the responseText nodes
    foreach ($this->responseText as $key => $val) {
      echo '<responseText' . (!is_numeric($key) && !empty($key) ? ' type="' . $key . '"' : '') . '><![CDATA[' . $val . ']]></responseText>' . "\n";
    }

    die('</root>');
    } elseif ($outputType == "JSON") {
      $data = array();

      // output the header for JSON
      header('Content-Type: application/json');

      // DO NOT set a JSON file DOCTYPE as there is none to be included.
//      echo '<?xml version="1.0" encoding="UTF-8" ' . "\n";

      // set the responseType
      $data['responseType'] = $this->responseType;
      // now loop through the responseText nodes
      foreach ($this->responseText as $key => $val) {
          if (!is_numeric($key) && !empty($key)) {
            $data['data'][$key] = $val;
          }
      }

      die(json_encode($data));
    }
  }
  
  // Add backwards compatibility
  /*
   *  Check if option name is not expected to have an option value (ie. text field, or File upload field)
   */
  public function zen_option_name_base_expects_no_values($option_name_id) {
    global $db, $zco_notifier;

    $option_name_no_value = true;
    if (!is_array($option_name_id)) {
      $option_name_id = array($option_name_id);
    }

    $sql = "SELECT products_options_type FROM " . TABLE_PRODUCTS_OPTIONS . " WHERE products_options_id :option_name_id:";
    if (count($option_name_id) > 1 ) {
      $sql2 = 'in (';
      foreach($option_name_id as $option_id) {
        $sql2 .= ':option_id:,';
        $sql2 = $db->bindVars($sql2, ':option_id:', $option_id, 'integer');
      }
      $sql2 = rtrim($sql2, ','); // Need to remove the final comma off of the above.
      $sql2 .= ')';
    } else {
      $sql2 = ' = :option_id:';
      $sql2 = $db->bindVars($sql2, ':option_id:', $option_name_id[0], 'integer');
    }

    $sql = $db->bindVars($sql, ':option_name_id:', $sql2, 'noquotestring');

    $sql_result = $db->Execute($sql);

    foreach($sql_result as $opt_type) {

      $test_var = true; // Set to false in observer if the name is not supposed to have a value associated
      $zco_notifier->notify('FUNCTIONS_LOOKUPS_OPTION_NAME_NO_VALUES_OPT_TYPE', $opt_type, $test_var);

      if ($test_var && $opt_type['products_options_type'] != PRODUCTS_OPTIONS_TYPE_TEXT && $opt_type['products_options_type'] != PRODUCTS_OPTIONS_TYPE_FILE) {
        $option_name_no_value = false;
        break;
      }
    }

    return $option_name_no_value;
  }
}

if (!function_exists('convertToFloat')) {
  function convertToFloat($input = 0)
  {
    if ($input === null) return 0;

    $val = preg_replace('/[^0-9,\.\-]/', '', $input);

    // do a non-strict compare here:
    if ($val == 0) return 0;

    return (float)$val;
  }
}
