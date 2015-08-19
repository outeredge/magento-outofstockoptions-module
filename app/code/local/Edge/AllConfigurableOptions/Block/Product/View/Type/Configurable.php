<?php

class Edge_AllConfigurableOptions_Block_Product_View_Type_Configurable
    extends Mage_Catalog_Block_Product_View_Type_Configurable
{
    protected $_sorts = array();

    protected function _prepareLayout()
    {
        $head = Mage::app()->getLayout()->getBlock('head');
        $head->addItem('skin_js', 'edge/allconfigurableoptions/configurable.js');
    }

    /**
     * Get Allowed Products
     * @return array
     */
    public function getAllowProducts()
    {
        if (!$this->hasAllowProducts()) {
            $products = $this->getProduct()->getTypeInstance(true)->getUsedProducts(null, $this->getProduct());
            $this->setAllowProducts($products);
        }
        return $this->getData('allow_products');
    }

    /**
    * Composes configuration for js
    * @return string
    */
    public function getJsonConfig()
    {
        $attributes = array();
        $options    = array();
        $store      = $this->getCurrentStore();
        $taxHelper  = Mage::helper('tax');
        $currentProduct = $this->getProduct();

        $preconfiguredFlag = $currentProduct->hasPreconfiguredValues();
        if ($preconfiguredFlag) {
            $preconfiguredValues = $currentProduct->getPreconfiguredValues();
            $defaultValues       = array();
        }

        foreach ($this->getAllowProducts() as $product) {
            $productId  = $product->getId();

            foreach ($this->getAllowAttributes() as $attribute) {
                $productAttribute   = $attribute->getProductAttribute();
                $productAttributeId = $productAttribute->getId();
                $attributeValue     = $product->getData($productAttribute->getAttributeCode());

                // Get stock of product
                $options['qty'][$product->getAttributeText($productAttribute->getName())] = floor($product->getStockItem()->getQty());

                if (!isset($options[$productAttributeId])) {
                    $options[$productAttributeId] = array();
                }

                if (!isset($options[$productAttributeId][$attributeValue])) {
                    $options[$productAttributeId][$attributeValue] = array();
                }
                $options[$productAttributeId][$attributeValue][] = $productId;
            }
        }

        $this->_resPrices = array(
            $this->_preparePrice($currentProduct->getFinalPrice())
        );

        foreach ($this->getAllowAttributes() as $attribute) {
            $productAttribute = $attribute->getProductAttribute();
            $attributeId = $productAttribute->getId();
            $info = array(
               'id'        => $productAttribute->getId(),
               'code'      => $productAttribute->getAttributeCode(),
               'label'     => $attribute->getLabel(),
               'options'   => array()
            );

            $optionPrices = array();
            $prices = $attribute->getPrices();
            if (is_array($prices)) {
                $prices = $this->_sortOptions($prices, $attributeId);
                foreach ($prices as $value) {
                    if(!$this->_validateAttributeValue($attributeId, $value, $options)) {
                        continue;
                    }
                    $currentProduct->setConfigurablePrice(
                        $this->_preparePrice($value['pricing_value'], $value['is_percent'])
                    );
                    $currentProduct->setParentId(true);
                    Mage::dispatchEvent(
                        'catalog_product_type_configurable_price',
                        array('product' => $currentProduct)
                    );
                    $configurablePrice = $currentProduct->getConfigurablePrice();

                    if (isset($options[$attributeId][$value['value_index']])) {
                        $productsIndex = $options[$attributeId][$value['value_index']];
                    } else {
                        $productsIndex = array();
                    }

                    $info['options'][] = array(
                        'id'        => $value['value_index'],
                        'label'     => ($options['qty'][$value['label']] <= 0) ? $this->__('Not available - %s', $value['label']) : $value['label'],
                        'price'     => $configurablePrice,
                        'oldPrice'  => $this->_prepareOldPrice($value['pricing_value'], $value['is_percent']),
                        'products'  => $productsIndex,
                        'disabled'  => $options['qty'][$value['label']] <= 0
                    );
                    $optionPrices[] = $configurablePrice;
                }
            }
            /**
             * Prepare formated values for options choose
             */
            foreach ($optionPrices as $optionPrice) {
                foreach ($optionPrices as $additional) {
                    $this->_preparePrice(abs($additional-$optionPrice));
                }
            }
            if($this->_validateAttributeInfo($info)) {
               $attributes[$attributeId] = $info;
            }

            // Add attribute default value (if set)
            if ($preconfiguredFlag) {
                $configValue = $preconfiguredValues->getData('super_attribute/' . $attributeId);
                if ($configValue) {
                    $defaultValues[$attributeId] = $configValue;
                }
            }
        }

        $taxCalculation = Mage::getSingleton('tax/calculation');
        if (!$taxCalculation->getCustomer() && Mage::registry('current_customer')) {
            $taxCalculation->setCustomer(Mage::registry('current_customer'));
        }

        $_request = $taxCalculation->getDefaultRateRequest();
        $_request->setProductClassId($currentProduct->getTaxClassId());
        $defaultTax = $taxCalculation->getRate($_request);

        $_request = $taxCalculation->getRateRequest();
        $_request->setProductClassId($currentProduct->getTaxClassId());
        $currentTax = $taxCalculation->getRate($_request);

        $taxConfig = array(
            'includeTax'        => $taxHelper->priceIncludesTax(),
            'showIncludeTax'    => $taxHelper->displayPriceIncludingTax(),
            'showBothPrices'    => $taxHelper->displayBothPrices(),
            'defaultTax'        => $defaultTax,
            'currentTax'        => $currentTax,
            'inclTaxTitle'      => Mage::helper('catalog')->__('Incl. Tax')
        );

        $config = array(
            'attributes'        => $attributes,
            'template'          => str_replace('%s', '#{price}', $store->getCurrentCurrency()->getOutputFormat()),
            'basePrice'         => $this->_registerJsPrice($this->_convertPrice($currentProduct->getFinalPrice())),
            'oldPrice'          => $this->_registerJsPrice($this->_convertPrice($currentProduct->getPrice())),
            'productId'         => $currentProduct->getId(),
            'chooseText'        => Mage::helper('catalog')->__('Choose an Option...'),
            'taxConfig'         => $taxConfig
        );

        if ($preconfiguredFlag && !empty($defaultValues)) {
            $config['defaultValues'] = $defaultValues;
        }

        $config = array_merge($config, $this->_getAdditionalConfig());

        return Mage::helper('core')->jsonEncode($config);
    }

    protected function _getSorts($attributeId)
    {
        if (null === $this->_sorts[$attributeId]) {
            $this->_sorts[$attributeId] = array_reduce(
                Mage::getResourceModel('eav/entity_attribute_option_collection')
                    ->setAttributeFilter($attributeId)
                    ->getData(),

                function(&$result, $item){
                    $result[$item['option_id']] = $item['sort_order'];
                    return $result;
                }
            );
        }
        return $this->_sorts[$attributeId];
    }

    protected function _sortOptions($options, $attributeId)
    {
        $sorts = $this->_getSorts($attributeId);
        $sorter = array();
        foreach ($options as $key => $option) {
            $sorter[$key] = $sorts[$option['value_index']];
        }
        array_multisort($sorter, SORT_ASC, $options);
        return $options;
    }
}
