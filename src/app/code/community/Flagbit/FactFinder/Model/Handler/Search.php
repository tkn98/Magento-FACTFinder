<?php
/**
 * Handles Search data for secondary channels
 *
 * @category    Mage
 * @package     Flagbit_FactFinder
 * @copyright   Copyright (c) 2013 Flagbit GmbH & Co. KG (http://www.flagbit.de/)
 * @author      Nicolai Essig <nicolai.essig@flagbit.de>
 * @author      Martin Buettner <martin.buettner@omikron.net>
 *
 **/
class Flagbit_FactFinder_Model_Handler_Search
    extends Flagbit_FactFinder_Model_Handler_Abstract
{
    protected $_currentFactFinderCategoryPath;

    protected $_searchResult;
    protected $_searchResultCount;
    protected $_campaigns;
    protected $_afterSearchNavigation;

    protected function configureFacade()
    {
        $params = $this->_collectParams();

        $this->_getFacade()->configureSearchAdapter($params);
    }

    /**
     * prepares all request parameters for the primary search adapter
     **/

    protected function _collectParams()
    {
        // search Helper
        $helper = Mage::helper('factfinder/search');
        $_request = Mage::app()->getRequest();
        $searchParams = $this->_getFacade()->getSearchParams();
        $params = array();

        if (strpos(Mage::getStoreConfig('factfinder/config/internal_ip'), Mage::helper('core/http')->getRemoteAddr()) !== false) {
            $params['log'] = 'internal';
        }

        switch($_request->getModuleName())
        {
            case "xmlconnect":
                $_query = $helper->getQueryText();
                $params['idsOnly'] = FF::getSingleton('configuration')->getIdsOnly() ? 'true' : 'false';
                $params['query'] = $_query;

                $count = $searchParams->getProductsPerPage() ? $searchParams->getProductsPerPage() : 0;
                if ($count > 0) {
                    $params['productsPerPage'] = $count;
                    $params['page'] = $searchParams->getCurrentPage();
                }

                // add Sorting Param
                foreach($searchParams->getSortings() as $key => $value){
                    if(substr($key, 0, 6) == 'order_') {
                        $key = substr($key, 6);
                        if(!in_array($key, array('position', 'relevance'))) {
                            $params['sort'.$key] = $value;
                        }
                    }
                }

                break;
            case "catalog":
                $params = array_merge($params, $this->_getCurrentFactFinderCategoryPath());

                if(Mage::getStoreConfig('factfinder/search/ffversion') >= 69) {
                    $params['navigation'] = 'true';
                } else {
                    $params['catalog'] = 'true';
                }

            case "catalogsearch":
            default:
                // add Default Params
                $params['idsOnly'] = $this->_getFacade()->getConfiguration()->getIdsOnly() ? 'true' : 'false';
                $params['productsPerPage'] = $helper->getPageLimit();

                if ($_request->getModuleName() == 'catalogsearch') {
                    $params['query'] = $helper->getQueryText();
                }
                $params['page'] = $helper->getCurrentPage();

                if($seoPath = Mage::app()->getRequest()->getParam('seoPath')) {
                    $params['seoPath'] = $seoPath;
                }

                // add Sorting Param, but only if it was set explicitly via url
                foreach($searchParams->getSortings() as $key => $value) {
                    if($key == 'order'
                        && $helper->getCurrentOrder()
                        && $helper->getCurrentDirection()
                        && $helper->getCurrentOrder() != 'position'
                        && $helper->getCurrentOrder() != 'relevance')
                    {
                        $params['sort'.$helper->getCurrentOrder()] = $helper->getCurrentDirection();
                    }
                }
                break;
        }

        return $params;
    }

    protected function _getCurrentFactFinderCategoryPath()
    {
        if($this->_currentFactFinderCategoryPath == null)
        {
            $this->_currentFactFinderCategoryPath = array();

            if(Mage::getStoreConfigFlag('factfinder/activation/navigation') && Mage::registry('current_category')){

                /* @var $category Mage_Catalog_Model_Category */
                $category = Mage::registry('current_category');

                $pathInStore = $category->getPathInStore();
                $pathIds = array_reverse(explode(',', $pathInStore));

                $categories = $category->getParentCategories();
                $mainCategoriesString = '';
                foreach ($pathIds as $categoryId) {
                    if (isset($categories[$categoryId]) && $categories[$categoryId]->getName())
                    {
                        $categoryName = html_entity_decode($categories[$categoryId]->getName());

                        if(empty($mainCategoriesString)) {
                            $this->_currentFactFinderCategoryPath['filtercategoryROOT'] = $categoryName;
                        } else {
                            $this->_currentFactFinderCategoryPath['filtercategoryROOT'.$mainCategoriesString] = $categoryName;
                        }
                        $mainCategoriesString .= '/'. str_replace('/', '%2F', $categoryName);
                    }
                }
            }
        }

        return $this->_currentFactFinderCategoryPath;
    }

    public function getAfterSearchNavigation()
    {
        if($this->_afterSearchNavigation == null) {
            $this->_afterSearchNavigation = array();

            $result = $this->_getFacade()->getAfterSearchNavigation();

            if ($result instanceof FACTFinder_Asn
                && count($result)) {

                foreach ($result as $row) {

                    $this->_afterSearchNavigation[] = array(
                        'attribute_code' => $row->getName(),
                        'name' => $row->getName(),
                        'unit' => $row->getUnit(),
                        'items' => $this->_getAttributeOptions($row->getArrayCopy(), $row->getUnit()),
                        'count' => $row->count(),
                        'type'    => $this->_getFilterType($row->getArrayCopy()),
                        'store_label' => $row->getName(),
                        'link_count' => $row->getDetailedLinkCount(),
                        'is_multiselect' => $row->isMultiSelectStyle()
                    );
                }
            }
        }
        return $this->_afterSearchNavigation;
    }

    /**
     * get Filter Type by FACT-Finder FilterItem
     *
     * @param array $options
     * @return string
     */
    protected function _getFilterType($options)
    {
        $defaultType = 'item';
        foreach($options as $option){
            if(!$option->getType()){
                continue;
            }
            $defaultType = $option->getType();
            break;
        }
        return $defaultType;
    }

    /**
     * get Attribute Options Array from FactFinder FilterGroupItems
     *
     * @param FACTFinder_AsnFilterItem $options
     * @param string $unit
     * @return array
     */
    protected function _getAttributeOptions($options, $unit = '')
    {
        $attributeOption = array();
        if (!empty($unit)) $unit = ' ' . $unit;
        $_currentCategoryPath = $this->_getCurrentFactfinderCategoryPath();
        $helper = Mage::helper('factfinder/search');
        foreach($options as $option)
        {
            $queryParams = Mage::helper('factfinder')->getQueryParams($option->getUrl());

            $_filterValue = $this->_getAttributeOptionValue($option);

            // Remove current categories from query params
            if(Mage::getStoreConfigFlag('factfinder/activation/navigation') && !$helper->getIsOnSearchPage())
            {
                foreach($_currentCategoryPath as $filterParam => $filterValue)
                {
                    if(isset($queryParams[$filterParam])) {
                        unset($queryParams[$filterParam]);
                    }
                }

                if(isset($queryParams['q']) && Mage::app()->getRequest()->getModuleName() == 'catalog') {
                    unset($queryParams['q']);
                }
            }

            $seoPath = '';
            if(isset($queryParams['seoPath'])) {
                $seoPath = $queryParams['seoPath'];
            }
            if((!empty($_filterValue) && !$helper->getIsOnSearchPage()) || $helper->getIsOnSearchPage()) {
                unset($queryParams['seoPath']);
            }

            switch ($option->getType())
            {
                case "slider":
                    $queryParams['filter'.$option->getField()] = $_filterValue;

                    $attributeOption[] = array(
                        'type'    => $option->getType(),
                        'label' => 'slider',
                        'value' => $this->_getAttributeOptionValue($option),
                        'absolute_min' => $option->getAbsoluteMin(),
                        'absolute_max' => $option->getAbsoluteMax(),
                        'selected_min' => $option->getSelectedMin(),
                        'selected_max' => $option->getSelectedMax(),
                        'count' => true,
                        'selected' => false, //$option->isSelected()
                        'requestVar' => 'filter'.$option->getField(),
                        'queryParams' => $queryParams
                    );
                    break;

                default:
                    if (!Mage::helper('core/string')->strlen($option->getValue())) {
                        continue;
                    }

                    // remove Categories from top Level Navigation
                    if(Mage::getStoreConfigFlag('factfinder/activation/navigation')
                        && !$helper->getIsOnSearchPage()
                        && strpos($option->getField(),'categoryROOT') !== FALSE
                        && in_array($option->getValue(), $_currentCategoryPath)
                    ){
                        continue;
                    }

                    $attributeOptionData = array(
                        'type'    => 'attribute',
                        'label' => $option->getValue() . $unit,
                        'value' => $_filterValue,
                        'count' => $option->getMatchCount(),
                        'selected' => $option->isSelected(),
                        'clusterLevel' => $option->getClusterLevel(),
                        'requestVar' => 'filter'.$option->getField(),
                        'previewImage' => $option->getPreviewImage()
                    );

                    $attributeOptionData['seoPath'] = $seoPath;
                    $attributeOptionData['queryParams'] = $queryParams;

                    $attributeOption[] = $attributeOptionData;
                    break;
            }
        }
        return $attributeOption;
    }

    /**
     * get Attribute option Value
     *
     * @param string $option
     * @return string
     */
    protected function _getAttributeOptionValue($option)
    {
        $value = null;
        switch ($option->getType()) {

            // handle Slider Attributes
            case "slider":
                $value = '[VALUE]';
                break;
            // handle default Attributes
            default:

                $queryParams = Mage::helper('factfinder')->getQueryParams($option->getUrl());

                if(isset($queryParams['filter'.$option->getField()])) {
                    $value = $queryParams['filter'.$option->getField()];
                } else {
                    $value = '';
                }

                break;
        }
        return $value;
    }

    public function getRedirect()
    {
        $url = null;
        $campaigns = $this->getCampaigns();

        if (!empty($campaigns) && $campaigns->hasRedirect()) {
            $url = $campaigns->getRedirectUrl();
        }
        return $url;
    }

    public function getCampaigns()
    {
        if($this->_campaigns === null)
        {
            $this->_campaigns = $this->_getFacade()->getCampaigns();
        }
        return $this->_campaigns;
    }

    public function getSearchResultCount()
    {
        if($this->_searchResultCount === null)
        {
            $result = $this->_getFacade()->getSearchResult();
            if($result instanceof \FACTFinder\Data\Result)
                $this->_searchResultCount = $result->getFoundRecordsCount();
            if($this->_searchResultCount === null)
                $this->_searchResultCount = 0;
        }
        return $this->_searchResultCount;
    }

    public function getSearchResult()
    {
        if($this->_searchResult === null) {
            $result = $this->_getFacade()->getSearchResult();
            $error = $this->_getFacade()->getSearchError();
            if($result === null || $error)
            {
                Mage::helper('factfinder/search')->registerFailedAttempt();
                Mage::logException(new Exception($error));
            }
            $this->_searchResult = array();

            if($result instanceof \FACTFinder\Data\Result) {
                foreach ($result AS $record){
                    if(isset($this->_searchResult[$record->getId()])) {
                        continue;
                    }
                    $this->_searchResult[$record->getId()] = new Varien_Object(
                        array(
                            'similarity' => $record->getSimilarity(),
                            'position' => $record->getPosition()
                        )
                    );
                }
            }
        }

        return $this->_searchResult;
    }


}