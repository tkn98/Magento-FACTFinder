<?php
/**
 * Flagbit_FactFinder
 *
 * @category  Mage
 * @package   Flagbit_FactFinder
 * @copyright Copyright (c) 2010 Flagbit GmbH & Co. KG (http://www.flagbit.de/)
 */

require_once BP.DS.'lib'.DS.'FACTFinder'.DS.'Loader.php';

use FACTFinder\Loader as FF;

// Possible status/error codes

define('FFE_OK',                        16180000);

define('FFE_CURL_ERROR',                16181000); // add the result of curl_errno() to this

define('FFE_HTTP_ERROR',                16182000); // add the HTTP code to this
define('FFE_WRONG_CONTEXT',             16182404); //

define('FFE_FACT_FINDER_ERROR',         16183000); // unspecified exception from FF; contact support
define('FFE_CHANNEL_DOES_NOT_EXIST',    16183001);
define('FFE_WRONG_CREDENTIALS',         16183002);
define('FFE_SERVER_TIME_MISMATCH',      16183003); // server time is not consistent with FF's server time

/**
 * Checks whether the configuration is working
 *
 * @category    Mage
 * @package     Flagbit_FactFinder
 * @copyright   Copyright (c) 2010 Flagbit GmbH & Co. KG (http://www.flagbit.de/)
 * @author      Martin Buettner <martin.buettner@omikron.net>
 * @version     $Id: CheckStatus.php 17.09.12 15:00 $
 *
 **/
class Flagbit_FactFinder_Model_Handler_CheckStatus
    extends Flagbit_FactFinder_Model_Handler_Search
{
    protected $_errorMessages = array();

    protected $_helper;

    protected $_secondaryChannels;

    /**
     * prepares all request parameters for the primary search adapter
     **/

    protected function _collectParams()
    {
        $this->_secondaryChannels = $this->_getFacade()->getConfiguration()->getSecondaryChannels();

        $params = array();
        $params['channel'] = $this->_getFacade()->getConfiguration()->getChannel();
        $params['query'] = 'FACT-Finder Version';
        $params['productsPerPage'] = '1';
        $params['verbose'] = 'true';

        return $params;
    }

    public function checkStatus()
    {
        $statusOkay = true;
        $this->_errorMessage = array();

        $primaryStatus = $this->getStatusCode();
        if($primaryStatus !== FFE_OK)
        {
            $this->_errorMessages[] = $this->_retrieveErrorMessage($primaryStatus);
            $statusOkay = false;
        }
        foreach($this->_secondaryChannels AS $channel)
        {
            $secondaryStatus = $this->_getFacade()->getFactFinderStatus($channel);
            if($secondaryStatus !== FFE_OK)
            {
                $this->_errorMessages[] = $this->_retrieveErrorMessage($secondaryStatus, $channel);
                $statusOkay = false;
            }
        }

        return $statusOkay;
    }

    protected function _retrieveErrorMessage($statusCode, $channel = null)
    {
        $helper = $this->_getHelper();
        if($channel === null)
            $errorMessage = $helper->__('Error in Primary Channel') . ': ';
        else
            $errorMessage = $helper->__('Error in Channel').' "'.$channel.'": ';

        switch($statusCode)
        {
        case FFE_WRONG_CONTEXT:
            $errorMessage .= $helper->__('FACT-Finder not found on server. Please check your context setting.');
            return $errorMessage;
        case FFE_CHANNEL_DOES_NOT_EXIST:
            $errorMessage .= $helper->__('Channel does not exist or the specified user does not have sufficient rights.');
            return $errorMessage;
        case FFE_WRONG_CREDENTIALS:
            $errorMessage .= $helper->__('Could not log into FACT-Finder with the given settings. Please check username, password, prefix and postfix.');
            return $errorMessage;
        case FFE_SERVER_TIME_MISMATCH:
            $errorMessage .= $helper->__('Your server\'s clock does not agree with FACT-Finder\'s. Please make sure your clock is set correctly.');
            return $errorMessage;
        }

        $codeType = floor($statusCode / 1000) * 1000;

        switch($codeType)
        {
        case FFE_CURL_ERROR:
            $errorMessage .= $helper->__('Could not establish HTTP connection.');
            $errorMessage .= ' cURL Error Code: '.($statusCode - $codeType);
            break;
        case FFE_HTTP_ERROR:
            $errorMessage .= $helper->__('Could not contact FACT-Finder.');
            $errorMessage .= ' HTTP Status Code: '.($statusCode - $codeType);
            break;
        case FFE_FACT_FINDER_ERROR:
            $errorMessage .= $helper->__('There is a problem with FACT-Finder. Please contact FACT-Finder Support.');
            break;
        default:
            $errorMessage .= $helper->__('An unknown error has occurred. Please contact FACT-Finder Support.');
            break;
        }

        return $errorMessage;
    }

    protected function _getHelper()
    {
        if($this->_helper === null)
            $this->_helper = Mage::helper('factfinder');
        return $this->_helper;
    }

    public function getErrorMessages()
    {
        return $this->_errorMessages;
    }

    public function getVersionNumber()
    {
        $resultCount = $this->_getFacade()->getSearchAdapter()->getResult()->getFoundRecordsCount();
        return intval(substr($resultCount, 0, 2));
    }

    public function getVersionString()
    {
        $versionNumber = ''.$this->getVersionNumber();
        return $versionNumber[0].'.'.$versionNumber[1];
    }

    public function getStatusCode()
    {
        /* start @todo solve this problem without reflection */
        $resultObj = $this->_getFacade()->getSearchAdapter();

        $reflectionClass = new ReflectionClass('FACTFinder\Adapter\Search');
        $property = $reflectionClass->getProperty('request');
        $property->setAccessible(true);

        $request = $property->getValue($resultObj);
        $response = $request->getResponse();

        $reflectionClass = new ReflectionClass('FACTFinder\Core\Server\Response');
        $httpCode = $reflectionClass->getProperty('httpCode');
        $httpCode->setAccessible(true);
        $connectionError = $reflectionClass->getProperty('connectionError');
        $connectionError->setAccessible(true);
        $connectionErrorCode = $reflectionClass->getProperty('connectionErrorCode');
        $connectionErrorCode->setAccessible(true);
        /* end  */

        try
        {
            $ffError = $this->_getFacade()->getSearchAdapter()->getError();
        }
        catch(Exception $e)
        {
            $ffError = $e->getMessage();
        }

        $curlErrno = $connectionErrorCode->getValue($response);

        switch($curlErrno)
        {
            case 0: // no cURL error!
                break;
            default:
                return FFE_CURL_ERROR + $connectionError->getValue($response);
        }

        // cURL was able to connect to the server, check HTTP Code next

        $httpCode = intval($httpCode->getValue($response));

        switch($httpCode)
        {
            case 200: // success!
                return FFE_OK;
            case 500: // server error, check error output
                break;
            default:
                return FFE_HTTP_ERROR + $httpCode;
        }

        $stackTrace = $this->_getFacade()->getSearchAdapter()->getStackTrace();
        preg_match('/^(.+?):?\s/', $stackTrace, $matches);
        $ffException = $matches[1];

        switch($ffException)
        {
            case 'de.factfinder.security.exception.ChannelDoesNotExistException':
                return FFE_CHANNEL_DOES_NOT_EXIST;
            case 'de.factfinder.security.exception.WrongUserPasswordException':
                return FFE_WRONG_CREDENTIALS;
            case 'de.factfinder.security.exception.PasswordExpiredException':
                return FFE_SERVER_TIME_MISMATCH;
            case 'de.factfinder.jni.FactFinderException':
            default:
                return FFE_FACT_FINDER_ERROR;
        }
    }
}
