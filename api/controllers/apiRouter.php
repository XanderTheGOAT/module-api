<?php

/**
 * Routes requests to the API to the appropriate controllers
 *
 * @package     Nails
 * @subpackage  module-api
 * @category    Controller
 * @author      Nails Dev Team
 * @link
 */

use Nails\Factory;

class ApiRouter extends Nails_Controller
{
    private $sRequestMethod;
    private $sModuleName;
    private $sClassName;
    private $sMethod;
    private $aParams;
    private $aOutputValidFormats;
    private $sOutputFormat;
    private $bOutputSendHeader;

    // --------------------------------------------------------------------------

    /**
     * Constructs the router, defining the request variables
     */
    public function __construct()
    {
        parent::__construct();

        // --------------------------------------------------------------------------

        //  Set defaults
        $this->aOutputValidFormats = array(
            'TXT',
            'JSON'
        );
        $this->bOutputSendHeader = true;

        // --------------------------------------------------------------------------

        //  Work out the request method
        $this->sRequestMethod = $this->input->server('REQUEST_METHOD');
        $this->sRequestMethod = $this->sRequestMethod ? $this->sRequestMethod : 'GET';

        /**
         * In order to work out the next few parts we'll analyse the URI string manually.
         * We're doing this ebcause of the optional return type at the end of the string;
         * it's easier to regex that quickly,r emove it, then split up the segments.
         */

        $uriString = uri_string();

        //  Get the format, if any
        $formatPattern = '/\.([a-z]*)$/';
        preg_match($formatPattern, $uriString, $matches);

        if (!empty($matches[1])) {

            $this->sOutputFormat = strtoupper($matches[1]);

            //  Remove the format from the string
            $uriString = preg_replace($formatPattern, '', $uriString);

        } else {

            $this->sOutputFormat = 'JSON';
        }

        //  Remove the module prefix (i.e "api/") then explode into segments
        //  Using regex as some systems will report a leading slash (e.g CLI)
        $uriString = preg_replace('#/?api/#', '', $uriString);
        $uriArray  = explode('/', $uriString);

        //  Work out the sModuleName, sClassName and method
        $this->sModuleName = array_key_exists(0, $uriArray) ? $uriArray[0] : null;
        $this->sClassName  = array_key_exists(1, $uriArray) ? $uriArray[1] : $this->sModuleName;
        $this->sMethod     = array_key_exists(2, $uriArray) ? $uriArray[2] : 'index';

        //  What's left of the array are the parameters to pass to the method
        $this->aParams = array_slice($uriArray, 3);

        //  Configure logging
        $oDateTime     = Factory::factory('DateTime');
        $this->oLogger = Factory::service('Logger');
        $this->oLogger->setFile('api-' . $oDateTime->format('y-m-d') . '.php');
    }

    // --------------------------------------------------------------------------

    /**
     * Route the call to the correct place
     * @return Void
     */
    public function index()
    {
        //  Handle OPTIONS CORS preflight requests
        if ($this->sRequestMethod === 'OPTIONS') {

            header('Access-Control-Allow-Origin: *');
            header('Access-Control-Allow-Headers: X-accesstoken, content, origin, content-type');
            header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');
            exit;

        } else {

            /**
             * If an access token has been passed then verify it
             *
             * Passing the token via the header is preferred, but fallback to the GET
             * and POST arrays.
             */

            $oUserAccessTokenModel = Factory::model('UserAccessToken', 'nailsapp/module-auth');
            $accessToken           = $this->input->get_request_header('X-accesstoken');

            if (!$accessToken) {

                $accessToken = $this->input->get_post('accessToken');
            }

            if ($accessToken) {

                $accessToken = $oUserAccessTokenModel->getByValidToken($accessToken);

                if ($accessToken) {

                    $this->user_model->setLoginData($accessToken->user_id, false);
                }
            }

            // --------------------------------------------------------------------------

            $aOut = array();

            if ($this->outputSetFormat($this->sOutputFormat)) {

                /**
                 * Look for a controller, app version first then the first one we
                 * find in the modules.
                 */
                $controllerPaths = array(
                    FCPATH . APPPATH . 'modules/api/controllers/'
                );

                $nailsModules = _NAILS_GET_MODULES();

                foreach ($nailsModules as $module) {

                    $controllerPaths[] = $module->path . 'api/controllers/';
                }

                //  Look for a valid controller
                $controllerName = ucfirst($this->sClassName) . '.php';

                foreach ($controllerPaths as $path) {

                    $fullPath = $path . $controllerName;

                    if (is_file($fullPath)) {

                        $controllerPath = $fullPath;
                        break;
                    }
                }

                if (!empty($controllerPath)) {

                    //  Load the file and try and execute the method
                    require_once $controllerPath;

                    $this->sModuleName = 'Nails\\Api\\' . ucfirst($this->sModuleName) . '\\' . ucfirst($this->sClassName);

                    if (class_exists($this->sModuleName)) {

                        $sClassName = $this->sModuleName;

                        if (!empty($sClassName::REQUIRE_AUTH) && !$this->user->isLoggedIn()) {

                            $aOut['status'] = 401;
                            $aOut['error']  = 'You must be logged in.';
                        }

                        /**
                         * If no errors and a scope is required, check the scope
                         */
                        if (empty($aOut) && !empty($sClassName::$requiresScope)) {


                            if (!$oUserAccessTokenModel->hasScope($accessToken, $sClassName::$requiresScope)) {

                                $aOut['status'] = 401;
                                $aOut['error']  = 'Access token with "' . $sClassName::$requiresScope;
                                $aOut['error'] .= '" scope is required.';
                            }
                        }

                        /**
                         * If no errors so far, begin execution
                         */
                        if (empty($aOut)) {

                            //  New instance of the controller
                            $instance = new $this->sModuleName($this);

                            /**
                             * We need to look for the appropriate method; we'll look in the following order:
                             *
                             * - {sRequestMethod}Remap()
                             * - {sRequestMethod}{method}()
                             * - anyRemap()
                             * - any{method}()
                             *
                             * The second parameter is whether the method is a remap method or not.
                             */

                            $aMethods = array(
                                array(
                                    strtolower($this->sRequestMethod) . 'Remap',
                                    true
                                ),
                                array(
                                    strtolower($this->sRequestMethod) . ucfirst($this->sMethod),
                                    false
                                ),
                                array(
                                    'anyRemap',
                                    true
                                ),
                                array(
                                    'any' . ucfirst($this->sMethod),
                                    false
                                )
                            );

                            $bDidFindRoute = false;

                            foreach ($aMethods as $aMethodName) {

                                if (is_callable(array($instance, $aMethodName[0]))) {

                                    /**
                                     * If the method we're trying to call is a remap method, then the first
                                     * param should be the name of the method being called
                                     */

                                    if ($aMethodName[1]) {

                                        $aParams = array_merge(array($this->sMethod), $this->aParams);

                                    } else {

                                        $aParams = $this->aParams;
                                    }

                                    $bDidFindRoute = true;
                                    $aOut       = call_user_func_array(array($instance, $aMethodName[0]), $aParams);
                                    break;
                                }
                            }

                            if (!$bDidFindRoute) {

                                $aOut['status'] = 404;
                                $aOut['error']  = '"' . $this->sRequestMethod . ': ' . $this->sModuleName . '/';
                                $aOut['error'] .= $this->sClassName . '/' . $this->sMethod;
                                $aOut['error'] .= '" is not a valid API route.';
                            }
                        }

                    } else {

                        $aOut['status'] = 500;
                        $aOut['error']  = '"' . $this->sModuleName . '" is incorrectly configured.';
                        $this->writeLog($aOut['error']);
                    }

                } else {

                    $aOut['status'] = 404;
                    $aOut['error']  = '"' . $this->sModuleName . '/' . $this->sMethod . '" is not a valid API route.';
                    $this->writeLog($aOut['error']);
                }

            } else {

                $aOut['status']   = 400;
                $aOut['error']    = '"' . $this->sOutputFormat . '" is not a valid format.';
                $this->writeLog($aOut['error']);
                $this->sOutputFormat = 'JSON';
            }

            $this->output($aOut);
        }
    }

    // --------------------------------------------------------------------------

    /**
     * Sends $aOut to the browser in the desired format
     * @param  array $aOut The data to output to the browser
     * @return void
     */
    protected function output($aOut = array())
    {
        //  Set cache headers
        $this->output->set_header('Cache-Control: no-store, no-cache, must-revalidate');
        $this->output->set_header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
        $this->output->set_header('Pragma: no-cache');

        //  Set access control headers
        $this->output->set_header('Access-Control-Allow-Origin: *');
        $this->output->set_header('Access-Control-Allow-Headers: X-accesstoken, content, origin, content-type');
        $this->output->set_header('Access-Control-Allow-Methods: GET, PUT, POST, DELETE, OPTIONS');

        $serverProtocol = $this->input->server('SERVER_PROTOCOL');

        // --------------------------------------------------------------------------

        //  Send the correct status header, default to 200 OK
        if (isset($aOut['status'])) {

            $aOut['status'] = (int) $aOut['status'];

            switch ($aOut['status']) {

                case 400:

                    $headerString = '400 Bad Request';
                    break;

                case 401:

                    $headerString = '401 Unauthorized';
                    break;

                case 404:

                    $headerString = '404 Not Found';
                    break;

                case 500:

                    $headerString = '500 Internal Server Error';
                    break;

                default:

                    $headerString = '200 OK';
                    break;

            }

        } elseif (is_array($aOut)) {

            $aOut['status'] = 200;
            $headerString  = '200 OK';

        } else {

            $headerString = '200 OK';
        }

        if ($this->bOutputSendHeader) {

            $this->output->set_header($serverProtocol . ' ' . $headerString);
        }

        // --------------------------------------------------------------------------

        //  Output content
        switch ($this->sOutputFormat) {

            case 'TXT':

                $aOut = $this->outputTxt($aOut);
                break;

            case 'JSON':

                $aOut = $this->outputJson($aOut);
                break;
        }

        $this->output->set_output($aOut);
    }

    // --------------------------------------------------------------------------

    /**
     * Formats $aOut as a plain text string formatted as JSON (for easy reading)
     * but a plaintext contentType
     * @param  array $aOut The result of the API call
     * @return string
     */
    private function outputTxt($aOut)
    {
        $this->output->set_content_type('text/html');
        return defined('JSON_PRETTY_PRINT') ? json_encode($aOut, JSON_PRETTY_PRINT) : json_encode($aOut);
    }

    // --------------------------------------------------------------------------

    /**
     * Formats $aOut as a JSON string
     * @param  array $aOut The result of the API call
     * @return string
     */
    private function outputJson($aOut)
    {
        $this->output->set_content_type('application/json');
        return defined('JSON_PRETTY_PRINT') ? json_encode($aOut, JSON_PRETTY_PRINT) : json_encode($aOut);
    }

    // --------------------------------------------------------------------------

    /**
     * Sets the output format
     * @param  string $format The format to use
     * @return boolean
     */
    public function outputSetFormat($format)
    {
        if ($this->isValidFormat($format)) {

            $this->sOutputFormat = strtoupper($format);
            return true;
        }

        return false;
    }

    // --------------------------------------------------------------------------

    /**
     * Sets whether the status header shoud be sent or not
     * @param  boolean $sendHeader Whether the ehader should be sent or not
     * @return void
     */
    public function outputSendHeader($sendHeader)
    {
        $this->bOutputSendHeader = !empty($sendHeader);
    }

    // --------------------------------------------------------------------------

    /**
     * Determines whether the format is valid
     * @param string The format to check
     * @return boolean
     */
    private function isValidFormat($format)
    {
        return in_array(strtoupper($format), $this->aOutputValidFormats);
    }

    // --------------------------------------------------------------------------

    /**
     * Write a line to the API log
     * @param string $sLine The line to write
     */
    public function writeLog($sLine)
    {
        $sLine  = ' [' . $this->sModuleName . '->' . $this->sMethod . '] ' . $sLine;
        $this->oLogger->line($sLine);
    }
}
