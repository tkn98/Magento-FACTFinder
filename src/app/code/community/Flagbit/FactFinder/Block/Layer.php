<?php
/**
 * Flagbit_FactFinder
 *
 * @category  Mage
 * @package   Flagbit_FactFinder
 * @copyright Copyright (c) 2010 Flagbit GmbH & Co. KG (http://www.flagbit.de/)
 */

/**
 * Block class
 *
 * This class is used to disable Magento´s default Price and Category Filter Output
 *
 * @category  Mage
 * @package   Flagbit_FactFinder
 * @copyright Copyright (c) 2010 Flagbit GmbH & Co. KG (http://www.flagbit.de/)
 * @author    Joerg Weller <weller@flagbit.de>
 * @version   $Id$
 */
class Flagbit_FactFinder_Block_Layer extends Flagbit_FactFinder_Block_Layer_Abstract
{
    protected $_searchHandler;

    protected $_usesAsn = false;

    /**
     * Prepare child blocks
     *
     * @return Mage_Catalog_Block_Layer_View
     */
    protected function _prepareLayout()
    {
        if(!Mage::helper('factfinder/search')->getIsEnabled()){
            return parent::_prepareLayout();
        }

        $this->_searchHandler = Mage::getSingleton('factfinder/handler_search');
        $this->_usesAsn = Mage::helper('factfinder/search')->getIsEnabled(false, 'asn');

        // Make this block globally known so that initializeAfterSearchNavigation can be called on this instance
        // when the controller_action_layout_generate_blocks_after event is fired
        Mage::register(Flagbit_FactFinder_Model_Observer::_asnBlockRegistryKey, $this, true);

        // Same for handling campaign redirects
        Mage::register(Flagbit_FactFinder_Model_Observer::_campaignRedirectRegistryKey, $this, true);

        return Mage_Core_Block_Template::_prepareLayout();
    }

    public function handleCampaignRedirect()
    {
        if(Mage::helper('factfinder/search')->getIsEnabled(false, 'campaign')){
            // handle redirects
            $redirect = $this->_searchHandler->getRedirect();
            if($redirect){
                $response = Mage::app()->getResponse();
                $response->setRedirect($redirect);
                $response->sendResponse();
                exit;
            }
        }
    }

    public function initializeAfterSearchNavigation()
    {
        if(!$this->_usesAsn)
            return;

        // set default sort Order
        if(Mage::getSingleton('catalog/session')->getSortOrder()){
            Mage::getSingleton('catalog/session')->setSortOrder('relevance');
        }

        $stateBlock = $this->getLayout()->createBlock('catalog/layer_state')
            ->setLayer($this->getLayer());

        $this->setChild('layer_state', $stateBlock);

        $filterableAttributes = $this->_getFilterableAttributes();
        foreach ($filterableAttributes as $attribute) {
            $filterBlockName = $this->_getAttributeFilterBlockName();

            $filterBlock = $this->getLayout()->createBlock($filterBlockName)
                ->setLayer($this->getLayer())
                ->setAttributeModel($attribute)
                ->init();

            if($attribute->getType() == 'slider') {
                if(!($this->getLayout()->getBlock('ffslider') instanceof  Flagbit_FactFinder_Block_Filter_Slider)){
                    $this->getLayout()->getBlock('head')->setChild('ffslider', $this->getLayout()->createBlock('factfinder/filter_slider'));
                }
                $filterBlock->setTemplate('factfinder/filter/slider.phtml');
                $filterBlock->setData((current($attribute->getItems())));
                $filterBlock->setUnit($attribute->getUnit());
            }

            $this->setChild($attribute->getAttributeCode().'_filter', $filterBlock);
        }

        $this->getLayer()->apply();
    }

    /**
     * Get category filter block
     *
     * @return Mage_Catalog_Block_Layer_Filter_Category
     */
    protected function _getCategoryFilter()
    {
        if(!Mage::helper('factfinder/search')->getIsEnabled(false, 'asn')){
            return parent::_getCategoryFilter();
        }
        return false;
    }

    /**
     * Retrieve Price Filter block
     *
     * @return Mage_Catalog_Block_Layer_Filter_Price
     */
    protected function _getPriceFilter()
    {
        if(!Mage::helper('factfinder/search')->getIsEnabled(false, 'asn')){
            return parent::_getPriceFilter();
        }

        return false;
    }

    /**
     * Check availability display layer block
     *
     * @return bool
     */
    public function canShowBlock()
    {
        if (!Mage::helper('factfinder/search')->getIsEnabled(false)
            || (!Mage::helper('factfinder/search')->getIsOnSearchPage() && !Mage::helper('factfinder/search')->getIsEnabled(false, 'asn')) ){
            return parent::canShowBlock();
        }
        if(!Mage::helper('factfinder/search')->getIsEnabled(false, 'asn')){
            return false;
        }
        if ($this->getLayer()->getFilterableAttributes()->count()
            && $this->getLayer()->getProductCollection()->getSize()) {
            return true;
        }
        return false;
    }
}
