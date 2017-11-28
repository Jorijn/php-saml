<?php


use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Main class of OneLogin's PHP Toolkit
 *
 */
class OneLogin_Saml2_Auth
{
    /**
     * Settings data.
     *
     * @var OneLogin_Saml2_Settings
     */
    private $_settings;

    /**
     * User attributes data.
     *
     * @var array
     */
    private $_attributes = array();

    /**
     * NameID
     *
     * @var string
     */
    private $_nameid;

    /**
     * NameID Format
     *
     * @var string
     */
    private $_nameidFormat;


    /**
     * NameID NameQualifier
     *
     * @var string
     */
    private $_nameidNameQualifier;

    /**
     * If user is authenticated.
     *
     * @var bool
     */
    private $_authenticated = false;


    /**
     * SessionIndex. When the user is logged, this stored it
     * from the AuthnStatement of the SAML Response
     *
     * @var string
     */
    private $_sessionIndex;

    /**
     * SessionNotOnOrAfter. When the user is logged, this stored it
     * from the AuthnStatement of the SAML Response
     *
     * @var int|null
     */
    private $_sessionExpiration;

    /**
     * The ID of the last message processed
     *
     * @var string
     */
    private $_lastMessageId;

    /**
     * The ID of the last assertion processed
     *
     * @var string
     */
    private $_lastAssertionId;

    /**
     * The NotOnOrAfter value of the valid SubjectConfirmationData
     * node (if any) of the last assertion processed
     *
     * @var int
     */
    private $_lastAssertionNotOnOrAfter;

    /**
     * If any error.
     *
     * @var array
     */
    private $_errors = array();

    /**
     * Reason of the last error.
     *
     * @var string|null
     */
    private $_errorReason;

    /**
     * Last AuthNRequest ID or LogoutRequest ID generated by this Service Provider
     *
     * @var string
     */
    private $_lastRequestID;

    /**
     * The most recently-constructed/processed XML SAML request
     * (AuthNRequest, LogoutRequest)
     *
     * @var string
     */
    private $_lastRequest;

    /**
     * The most recently-constructed/processed XML SAML response
     * (SAMLResponse, LogoutResponse). If the SAMLResponse was
     * encrypted, by default tries to return the decrypted XML
     *
     * @var string
     */
    private $_lastResponse;

    protected $utils;

    /**
     * Initializes the SP SAML instance.
     *
     * @param array|object|null $oldSettings Setting data (You can provide a OneLogin_Saml_Settings, the settings object of the Saml folder implementation)
     */
    public function __construct(UrlGeneratorInterface $urlGenerator, $oldSettings = null)
    {
        $this->utils = new OneLogin_Saml2_Utils($urlGenerator);
        $this->_settings = new OneLogin_Saml2_Settings($this->utils, $oldSettings);
    }

    /**
     * Returns the settings info
     *
     * @return OneLogin_Saml2_Settings The settings data.
     */
    public function getSettings()
    {
        return $this->_settings;
    }

    /**
     * Set the strict mode active/disable
     *
     * @param bool $value Strict parameter
     * @throws OneLogin_Saml2_Error
     */
    public function setStrict($value)
    {
        if (! (is_bool($value))) {
            throw new OneLogin_Saml2_Error(
                'Invalid value passed to setStrict()',
                OneLogin_Saml2_Error::SETTINGS_INVALID_SYNTAX
            );
        }

        $this->_settings->setStrict($value);
    }

    /**
     * Process the SAML Response sent by the IdP.
     *
     * @param string $samlResponse
     * @param string|null $requestId The ID of the AuthNRequest sent by this SP to the IdP
     *
     * @throws OneLogin_Saml2_Error
     */
    public function processResponse($samlResponse, $requestId = null)
    {
        $this->_errors = array();
        $this->_errorReason = null;
        if (null !== $samlResponse) {
            // AuthnResponse -- HTTP_POST Binding
            $response = new OneLogin_Saml2_Response($this->utils, $this->_settings, $samlResponse);
            $this->_lastResponse = $response->getXMLDocument();

            if ($response->isValid($requestId)) {
                $this->_attributes = $response->getAttributes();
                $this->_nameid = $response->getNameId();
                $this->_nameidFormat = $response->getNameIdFormat();
                $this->_nameidNameQualifier = $response->getNameIdNameQualifier();
                $this->_authenticated = true;
                $this->_sessionIndex = $response->getSessionIndex();
                $this->_sessionExpiration = $response->getSessionNotOnOrAfter();
                $this->_lastMessageId = $response->getId();
                $this->_lastAssertionId = $response->getAssertionId();
                $this->_lastAssertionNotOnOrAfter = $response->getAssertionNotOnOrAfter();
            } else {
                $this->_errors[] = 'invalid_response';
                $this->_errorReason = $response->getError();
            }
        } else {
            $this->_errors[] = 'invalid_binding';
            throw new OneLogin_Saml2_Error(
                'SAML Response not found, Only supported HTTP_POST Binding',
                OneLogin_Saml2_Error::SAML_RESPONSE_NOT_FOUND
            );
        }
    }

    /**
     * Process the SAML Logout Response / Logout Request sent by the IdP.
     *
     * @param Request     $request
     * @param bool        $keepLocalSession              When false will destroy the local session, otherwise will keep it
     * @param string|null $requestId                     The ID of the LogoutRequest sent by this SP to the IdP
     * @param bool        $retrieveParametersFromServer
     * @param callable    $cbDeleteSession
     * @param bool        $stay                          True if we want to stay (returns the url string) False to redirect
     *
     * @return string|void
     *
     * @throws OneLogin_Saml2_Error
     */
    public function processSLO(
        Request $request,
        $keepLocalSession = false,
        $requestId = null,
        $retrieveParametersFromServer = false,
        $cbDeleteSession = null,
        $stay = false
    ) {
        $this->_errors = array();
        $this->_errorReason = null;
        if (null !== ($samlResponse = $request->get('SAMLResponse'))) {
            $logoutResponse = new OneLogin_Saml2_LogoutResponse($this->utils, $this->_settings, $samlResponse);
            $this->_lastResponse = $logoutResponse->getXML();
            if (!$logoutResponse->isValid($request, $requestId, $retrieveParametersFromServer)) {
                $this->_errors[] = 'invalid_logout_response';
                $this->_errorReason = $logoutResponse->getError();
            } elseif ($logoutResponse->getStatus() !== OneLogin_Saml2_Constants::STATUS_SUCCESS) {
                $this->_errors[] = 'logout_not_success';
            } else {
                $this->_lastMessageId = $logoutResponse->id;
                if (!$keepLocalSession) {
                    if ($cbDeleteSession === null) {
                        $this->utils->deleteLocalSession();
                    } else {
                        call_user_func($cbDeleteSession);
                    }
                }
            }
        } elseif (null !== ($samlRequest = $request->get('SAMLRequest'))) {
            $logoutRequest = new OneLogin_Saml2_LogoutRequest($this->_settings, $samlRequest);
            $this->_lastRequest = $logoutRequest->getXML();
            if (!$logoutRequest->isValid($request, $retrieveParametersFromServer)) {
                $this->_errors[] = 'invalid_logout_request';
                $this->_errorReason = $logoutRequest->getError();
            } else {
                if (!$keepLocalSession) {
                    if ($cbDeleteSession === null) {
                        $this->utils->deleteLocalSession();
                    } else {
                        call_user_func($cbDeleteSession);
                    }
                }
                $inResponseTo = $logoutRequest->id;
                $this->_lastMessageId = $logoutRequest->id;
                $responseBuilder = new OneLogin_Saml2_LogoutResponse($this->utils, $this->_settings);
                $responseBuilder->build($inResponseTo);
                $this->_lastResponse = $responseBuilder->getXML();

                $logoutResponse = $responseBuilder->getResponse();

                $parameters = array('SAMLResponse' => $logoutResponse);
                if (null !== ($relayState = $request->get('RelayState'))) {
                    $parameters['RelayState'] = $relayState;
                }

                $security = $this->_settings->getSecurityData();
                if (isset($security['logoutResponseSigned']) && $security['logoutResponseSigned']) {
                    $signature = $this->buildResponseSignature(
                        $logoutResponse,
                        isset($parameters['RelayState'])? $parameters['RelayState']: null,
                        $security['signatureAlgorithm']
                    );
                    $parameters['SigAlg'] = $security['signatureAlgorithm'];
                    $parameters['Signature'] = $signature;
                }

                return $this->redirectTo($this->getSLOurl(), $parameters, $stay);
            }
        } else {
            $this->_errors[] = 'invalid_binding';
            throw new OneLogin_Saml2_Error(
                'SAML LogoutRequest/LogoutResponse not found. Only supported HTTP_REDIRECT Binding',
                OneLogin_Saml2_Error::SAML_LOGOUTMESSAGE_NOT_FOUND
            );
        }
    }

    /**
     * Redirects the user to the url past by parameter
     * or to the url that we defined in our SSO Request.
     *
     * @param string $url        The target URL to redirect the user.
     * @param array  $parameters Extra parameters to be passed as part of the url
     * @param bool   $stay       True if we want to stay (returns the url string) False to redirect
     * @return string|RedirectResponse
     */
    public function redirectTo($url = '', $parameters = array(), $stay = false)
    {
        assert('is_string($url)');
        assert('is_array($parameters)');


        /**
         * This function doesn't seem to be called without $url parameter.
         *
         * if (empty($url) && isset($_REQUEST['RelayState'])) {
         *   $url = $_REQUEST['RelayState'];
         * }
         */

        if ($stay) {
            return $this->utils->redirect($url, $parameters, $stay);
        }

        return new RedirectResponse($this->utils->redirect($url, $parameters, true));
    }

    /**
     * Checks if the user is authenticated or not.
     *
     * @return bool  True if the user is authenticated
     */
    public function isAuthenticated()
    {
        return $this->_authenticated;
    }

    /**
     * Returns the set of SAML attributes.
     *
     * @return array  Attributes of the user.
     */
    public function getAttributes()
    {
        return $this->_attributes;
    }

    /**
     * Returns the nameID
     *
     * @return string  The nameID of the assertion
     */
    public function getNameId()
    {
        return $this->_nameid;
    }

    /**
     * Returns the nameID Format
     *
     * @return string  The nameID Format of the assertion
     */
    public function getNameIdFormat()
    {
        return $this->_nameidFormat;
    }

    /**
     * Returns the nameID NameQualifier
     *
     * @return string  The nameID NameQualifier of the assertion
     */
    public function getNameIdNameQualifier()
    {
        return $this->_nameidNameQualifier;
    }

    /**
     * Returns the SessionIndex
     *
     * @return string|null  The SessionIndex of the assertion
     */
    public function getSessionIndex()
    {
        return $this->_sessionIndex;
    }

    /**
     * Returns the SessionNotOnOrAfter
     *
     * @return DateTime|null  The SessionNotOnOrAfter of the assertion
     */
    public function getSessionExpiration()
    {
        return $this->_sessionExpiration;
    }

    /**
     * Returns if there were any error
     *
     * @return array  Errors
     */
    public function getErrors()
    {
        return $this->_errors;
    }

    /**
     * Returns the reason for the last error
     *
     * @return string|null Error reason
     */
    public function getLastErrorReason()
    {
        return $this->_errorReason;
    }

    /**
     * Returns the requested SAML attribute
     *
     * @param string $name The requested attribute of the user.
     *
     * @return array|null Requested SAML attribute ($name).
     */
    public function getAttribute($name)
    {
        assert('is_string($name)');

        $value = null;
        if (isset($this->_attributes[$name])) {
            return $this->_attributes[$name];
        }
        return $value;
    }

    /**
     * Initiates the SSO process.
     *
     * @param string|null $returnTo        The target URL the user should be returned to after login.
     * @param array       $parameters      Extra parameters to be added to the GET
     * @param bool        $forceAuthn      When true the AuthNReuqest will set the ForceAuthn='true'
     * @param bool        $isPassive       When true the AuthNReuqest will set the Ispassive='true'
     * @param bool        $stay            True if we want to stay (returns the url string) False to redirect
     * @param bool        $setNameIdPolicy When true the AuthNReuqest will set a nameIdPolicy element
     *
     * @return string|null If $stay is True, it return a string with the SLO URL + LogoutRequest + parameters
     */
    public function login($returnTo = null, $parameters = array(), $forceAuthn = false, $isPassive = false, $stay = false, $setNameIdPolicy = true)
    {
        assert('is_array($parameters)');

        $authnRequest = new OneLogin_Saml2_AuthnRequest($this->utils, $this->_settings, $forceAuthn, $isPassive, $setNameIdPolicy);

        $this->_lastRequest = $authnRequest->getXML();
        $this->_lastRequestID = $authnRequest->getId();

        $samlRequest = $authnRequest->getRequest();
        $parameters['SAMLRequest'] = $samlRequest;

        if (!empty($returnTo)) {
            $parameters['RelayState'] = $returnTo;
        } else {
            $parameters['RelayState'] = $this->utils->getSelfRoutedURLNoQuery();
        }

        $security = $this->_settings->getSecurityData();
        if (isset($security['authnRequestsSigned']) && $security['authnRequestsSigned']) {
            $signature = $this->buildRequestSignature($samlRequest, $parameters['RelayState'], $security['signatureAlgorithm']);
            $parameters['SigAlg'] = $security['signatureAlgorithm'];
            $parameters['Signature'] = $signature;
        }
        return $this->redirectTo($this->getSSOurl(), $parameters, $stay);
    }

    /**
     * Initiates the SLO process.
     *
     * @param string|null $returnTo            The target URL the user should be returned to after logout.
     * @param array       $parameters          Extra parameters to be added to the GET
     * @param string|null $nameId              The NameID that will be set in the LogoutRequest.
     * @param string|null $sessionIndex        The SessionIndex (taken from the SAML Response in the SSO process).
     * @param bool        $stay                True if we want to stay (returns the url string) False to redirect
     * @param string|null $nameIdFormat        The NameID Format will be set in the LogoutRequest.
     * @param string|null $nameIdNameQualifier The NameID NameQualifier will be set in the LogoutRequest.
     *
     * @return string|Response If $stay is True, it return a string with the SLO URL + LogoutRequest + parameters
     *
     * @throws OneLogin_Saml2_Error
     */
    public function logout($returnTo = null, $parameters = array(), $nameId = null, $sessionIndex = null, $stay = false, $nameIdFormat = null, $nameIdNameQualifier = null)
    {
        assert('is_array($parameters)');

        $sloUrl = $this->getSLOurl();
        if (empty($sloUrl)) {
            throw new OneLogin_Saml2_Error(
                'The IdP does not support Single Log Out',
                OneLogin_Saml2_Error::SAML_SINGLE_LOGOUT_NOT_SUPPORTED
            );
        }

        if (empty($nameId) && !empty($this->_nameid)) {
            $nameId = $this->_nameid;
        }
        if (empty($nameIdFormat) && !empty($this->_nameidFormat)) {
            $nameIdFormat = $this->_nameidFormat;
        }

        $logoutRequest = new OneLogin_Saml2_LogoutRequest($this->utils, $this->_settings, null, $nameId, $sessionIndex, $nameIdFormat, $nameIdNameQualifier);

        $this->_lastRequest = $logoutRequest->getXML();
        $this->_lastRequestID = $logoutRequest->id;

        $samlRequest = $logoutRequest->getRequest();

        $parameters['SAMLRequest'] = $samlRequest;
        if (!empty($returnTo)) {
            $parameters['RelayState'] = $returnTo;
        } else {
            $parameters['RelayState'] = $this->utils->getSelfRoutedURLNoQuery();
        }

        $security = $this->_settings->getSecurityData();
        if (isset($security['logoutRequestSigned']) && $security['logoutRequestSigned']) {
            $signature = $this->buildRequestSignature($samlRequest, $parameters['RelayState'], $security['signatureAlgorithm']);
            $parameters['SigAlg'] = $security['signatureAlgorithm'];
            $parameters['Signature'] = $signature;
        }

        return $this->redirectTo($sloUrl, $parameters, $stay);
    }

    /**
     * Gets the SSO url.
     *
     * @return string The url of the Single Sign On Service
     */
    public function getSSOurl()
    {
        $idpData = $this->_settings->getIdPData();
        return $idpData['singleSignOnService']['url'];
    }

    /**
     * Gets the SLO url.
     *
     * @return string The url of the Single Logout Service
     */
    public function getSLOurl()
    {
        $url = null;
        $idpData = $this->_settings->getIdPData();
        if (isset($idpData['singleLogoutService']) && isset($idpData['singleLogoutService']['url'])) {
            $url = $idpData['singleLogoutService']['url'];
        }
        return $url;
    }

    /**
     * Gets the ID of the last AuthNRequest or LogoutRequest generated by the Service Provider.
     *
     * @return string The ID of the Request SAML message.
     */
    public function getLastRequestID()
    {
        return $this->_lastRequestID;
    }

    /**
     * Generates the Signature for a SAML Request
     *
     * @param string $samlRequest    The SAML Request
     * @param string $relayState     The RelayState
     * @param string $signAlgorithm Signature algorithm method
     *
     * @return string A base64 encoded signature
     *
     * @throws Exception
     * @throws OneLogin_Saml2_Error
     */
    public function buildRequestSignature($samlRequest, $relayState, $signAlgorithm = XMLSecurityKey::RSA_SHA1)
    {
        $key = $this->_settings->getSPkey();
        if (empty($key)) {
            throw new OneLogin_Saml2_Error(
                "Trying to sign the SAML Request but can't load the SP private key",
                OneLogin_Saml2_Error::PRIVATE_KEY_NOT_FOUND
            );
        }

        $key = $this->_settings->getSPkey();

        $objKey = new XMLSecurityKey($signAlgorithm, array('type' => 'private'));
        $objKey->loadKey($key, false);

        $security = $this->_settings->getSecurityData();
        if ($security['lowercaseUrlencoding']) {
            $msg = 'SAMLRequest='.rawurlencode($samlRequest);
            if (isset($relayState)) {
                $msg .= '&RelayState='.rawurlencode($relayState);
            }
            $msg .= '&SigAlg=' . rawurlencode($signAlgorithm);
        } else {
            $msg = 'SAMLRequest='.urlencode($samlRequest);
            if (isset($relayState)) {
                $msg .= '&RelayState='.urlencode($relayState);
            }
            $msg .= '&SigAlg=' . urlencode($signAlgorithm);
        }
        $signature = $objKey->signData($msg);
        return base64_encode($signature);
    }

    /**
     * Generates the Signature for a SAML Response
     *
     * @param string $samlResponse  The SAML Response
     * @param string $relayState    The RelayState
     * @param string $signAlgorithm Signature algorithm method
     *
     * @return string A base64 encoded signature
     *
     * @throws Exception
     * @throws OneLogin_Saml2_Error
     */
    public function buildResponseSignature($samlResponse, $relayState, $signAlgorithm = XMLSecurityKey::RSA_SHA1)
    {
        $key = $this->_settings->getSPkey();
        if (empty($key)) {
            throw new OneLogin_Saml2_Error(
                "Trying to sign the SAML Response but can't load the SP private key",
                OneLogin_Saml2_Error::PRIVATE_KEY_NOT_FOUND
            );
        }

        $objKey = new XMLSecurityKey($signAlgorithm, array('type' => 'private'));
        $objKey->loadKey($key, false);

        $security = $this->_settings->getSecurityData();
        if ($security['lowercaseUrlencoding']) {
            $msg = 'SAMLResponse='.rawurlencode($samlResponse);
            if (isset($relayState)) {
                $msg .= '&RelayState='.rawurlencode($relayState);
            }
            $msg .= '&SigAlg=' . rawurlencode($signAlgorithm);
        } else {
            $msg = 'SAMLResponse='.urlencode($samlResponse);
            if (isset($relayState)) {
                $msg .= '&RelayState='.urlencode($relayState);
            }
            $msg .= '&SigAlg=' . urlencode($signAlgorithm);
        }
        $signature = $objKey->signData($msg);
        return base64_encode($signature);
    }

    /**
     * @return string The ID of the last message processed
     */
    public function getLastMessageId()
    {
        return $this->_lastMessageId;
    }

    /**
     * @return string The ID of the last assertion processed
     */
    public function getLastAssertionId()
    {
        return $this->_lastAssertionId;
    }

    /**
     * @return int The NotOnOrAfter value of the valid
     *         SubjectConfirmationData node (if any)
     *         of the last assertion processed
     */
    public function getLastAssertionNotOnOrAfter()
    {
        return $this->_lastAssertionNotOnOrAfter;
    }

    /**
     * Returns the most recently-constructed/processed
     * XML SAML request (AuthNRequest, LogoutRequest)
     *
     * @return string The Request XML
     */
    public function getLastRequestXML()
    {
        return $this->_lastRequest;
    }

    /**
     * Returns the most recently-constructed/processed
     * XML SAML response (SAMLResponse, LogoutResponse).
     * If the SAMLResponse was encrypted, by default tries
     * to return the decrypted XML.
     *
     * @return string|null The Response XML
     */
    public function getLastResponseXML()
    {
        $response = null;
        if (isset($this->_lastResponse)) {
            if (is_string($this->_lastResponse)) {
                $response = $this->_lastResponse;
            } else {
                $response = $this->_lastResponse->saveXML();
            }
        }
        
        return $response;
    }
}
