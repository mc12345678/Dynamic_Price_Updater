<?php  
  $autoLoadConfig[0][] = array('autoType'=>'class',
                              'loadFile'=>'observers/class.get_products_base_price_attr_qty_price.php');
// Be sure that observer is available before first time notifier needs to be observed.
  $autoLoadConfig[55][] = array(
          'autoType' => 'classInstantiate',
          'className' => 'zcObserverGetProductsBasePriceAttrQtyPrice',
          'objectName' => 'zcObserverGetProductsBasePriceAttrQtyPriceObserve'
  );
// eof