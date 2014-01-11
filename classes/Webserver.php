<?php
/*
Webserver.php - Pure (native) PHP web server implementation with SSL support
Copyright (C) 2013  Bas Peters <bas@baspeters.com> & Jeroen Vermeulen <info@jeroenvermeulen.eu>

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program.  If not, see <http://www.gnu.org/licenses/>.
*/
/**
 * Pure (native) PHP web server implementation with SSL support
 *
 * @category    Web Server
 * @package     ClusterStatsDaemon
 * @author      Bas Peters <bas@baspeters.com>
 * @author      Jeroen Vermeulen <info@jeroenvermeulen.eu>
 */
class Webserver {
    /**
     * Private class properties
     */
    private $_startTime = null;
    private $_webserverSocket = null;
    private $_webserverControllerMap = array();
    private $_webserverPemFile = null;
    private $_webserverWebRoot = null;
    private $_webserverUsername = null;
    private $_webserverPassword = null;
    private $_webserverContentTypes = array(
        'html' => 'text/html',
        'txt'  => 'text/plain',
        'png'  => 'image/png',
        'jpg'  => 'image/jpg',
        'gif'  => 'image/gif',
        'js'   => 'application/x-javascript',
        'css'  => 'text/css',
        'ico'  => 'image/vnd.microsoft.icon'
    );

    /**
     * Constructor
     */
    public function __construct() {
        $this->_startTime = time();
        $this->_webserverPemFile = APP_ROOT . 'includes' . DIRECTORY_SEPARATOR . 'server.pem';
        $this->_initialize();
    }

    /**
     * Destructor
     */
    public function __destruct() {

    }

    /**
     * Registers a web server controller to invoke when a specific url path is requested
     * from the internal web server
     *
     * @param string $paths The url path or an array of paths
     * @param callback $callback The function or method to be called
     * @param string $contentType HTML content type
     * @throws Exception
     */
    public function registerController($paths, $callback, $contentType = null) {
        if (!is_callable($callback)) {
            throw new Exception('Callback function is not callable for this webserver controller');
        }
        if (!is_array($callback) && strpos($callback,'::')) {
            $funcArray = strpos($callback,'::') ? explode('::',$callback) : $callback;
            $func = new ReflectionMethod($funcArray[0],$funcArray[1]);
        } elseif (is_array($callback)) {
            $func = new ReflectionMethod($callback[0],$callback[1]);
        } else {
            $func = new ReflectionFunction($callback);
        }
        if (($paramCount=$func->getNumberOfParameters()) > 1) {
            throw new Exception( sprintf( "Webserver controller callback '%s' function should have a 0 or 1 parameters, %d given"
                                        , $func->name
                                        , $paramCount ) );
        }

        if (!is_array($paths)) $paths = array($paths);

        foreach ($paths as $path) {
            $this->_webserverControllerMap[$path] = array(
                'callback' => $callback,
                'content_type' => $contentType
            );
        }
    }

    /**
     * Sets the login credentials for the web server
     *
     * @param string $username Username
     * @param string $password Password
     */
    public function setCredentials($username, $password) {
        $this->_webserverUsername = $username;
        $this->_webserverPassword = $password;
    }

    /**
     * Sets the webroot location of the web server
     *
     * @param string $webRoot The absolute location of the webroot
     * @throws Exception
     */
    public function setRoot($webRoot) {
        if (!file_exists($webRoot)) {
            throw new Exception('The specified webroot does not exist and cannot be set');
        }
        $this->_webserverWebRoot = realpath($webRoot);
    }

    /**
     * Handles an individual connecting web client
     *
     * @param int $timeout The timeout period to wait for a connection
     */
    public function handleClients($timeout = 250000) {
        // convert microseconds to seconds
        $timeoutSeconds = $timeout/1000000;
        $client = @stream_socket_accept($this->_webserverSocket, $timeoutSeconds, $peer);
        if($client===false) return;

        socket_set_timeout($client,5);

        // read header information of the connecting web browser

        $startTime = microtime( true );

        $requestHeader = '';
        while (!feof($client)) {
            $requestHeader .= $line = @fgets($client, 1024);
            if($line==="\r\n" || $line===false) break;
            if ( 5 < microtime(true) - $startTime) {
                Log::info("webclient {$peer} timeout when reading request headers");
                @fclose($client);
                return;
            }
        }

        // ignore empty requests
        if(strlen($requestHeader)===0) return;

        // determine what the requested path is
        preg_match('/^get\s(?P<location>.*?)\shttp\/1.[01]/i',$requestHeader, $matches);
        if(!(isset($matches['location']))) {
            Log::info("webclient {$peer} sent a non-valid request ");
            @fclose($client);
            return;
        }
        $location = $matches['location'];

        $hostname = '';
        if ( preg_match('/Host\s*:\s*([a-zA-Z0-9\.]+(:\d+)?)+\s*/',$requestHeader, $matches) ) {
            $hostname = $matches[1];
        }

        // check if the webclient is capable of retrieving gzipped or deflated contents
        preg_match('/Accept-Encoding\s*:\s*(?P<encodings>.*)/', $requestHeader, $matches);
        if(isset($matches['encodings'])) {
            $allowedEncodings = explode(',', strtolower($matches['encodings']));
            array_walk($allowedEncodings, 'trim');
            if(in_array('gzip', $allowedEncodings)) {
                $responseEncoding = 'gzip';
            } elseif(in_array('deflate', $allowedEncodings)) {
                $responseEncoding = 'deflate';
            } else {
                $responseEncoding = 'identity';
            }
        } else {
            $responseEncoding = 'identity';
        }

        // break down the location, query string and set the correct content type
        $locationArray = explode('?',$location);
        $path = $locationArray[0];
        $extension =  strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $queryString = isset($locationArray[1]) ? $locationArray[1] : '';
        if(empty($extension) || !isset($this->_webserverContentTypes[$extension])) {
            $contentType = 'text/html';
        } else {
            $contentType = $this->_webserverContentTypes[$extension];
        }

        // check if the right credentials were supplied
        if(!$this->_clientCheckCredentials($requestHeader)) {
            $this->_clientSendUnauthorized($client);
            Log::info("webclient {$peer} failed to authenticate");
            return;
        }

        // Check which type of request the client requested
        if(isset($this->_webserverControllerMap[$path])) {
            try {
                // call the callback function for this controller
                // if the callback results in a runtime error, produce an error page
                if(!is_null($this->_webserverControllerMap[$path]['content_type'])) {
                    $contentType = $this->_webserverControllerMap[$path]['content_type'];
                }
                $protocol = ( Config::get('http_ssl_enabled') ) ? 'https' : 'http';
                $requestInfo = array( 'PATH' => $path
                                    , 'REQUEST_URI' => $location
                                    , 'QUERY_STRING' => $queryString
                                    , 'HTTP_HOST' => $hostname
                                    , 'URL' => sprintf( "%s://%s%s", $protocol, $hostname, $location ) );
                if ( 'https' == $protocol ) {
                    $requestInfo[ 'HTTPS' ] = 'on';
                }
                $responseBody = call_user_func( $this->_webserverControllerMap[$path]['callback'], $requestInfo );
                if(!is_scalar($responseBody)) {
                    throw new Exception("Invalid callback return value from web controller '{$path}'");
                }
            } catch(Exception $e) {
                $this->_clientSendInternalServerError($client);
                Log::error($e->getMessage());
                Log::info("webclient {$peer} request triggered an error");
                return;
            }
        } elseif($requestFile = realpath($this->_webserverWebRoot.$path)) {
            // compile the real pathname of the requested webfile
            $requestFile = realpath($this->_webserverWebRoot.$path);
            // check if through path traversal the webrequest ended outside of the allowed webroot
            // if it did, it is probably a hacking attempt so send a forbidden error
            if(strncmp($this->_webserverWebRoot, $requestFile, strlen($this->_webserverWebRoot))!==0) {
                $this->_clientSendForbidden($client, $path);
                Log::info("webclient {$peer} request {$path} was forbidden");
               return;
            }

            // there is a resource in the webserver root that matches the request
            // check if the file is readable and the extension is allowed
            if(!is_readable($requestFile) || empty($extension) || !isset($this->_webserverContentTypes[$extension])) {
                $this->_clientSendForbidden($client, $path);
                Log::info("webclient {$peer} request {$path} was forbidden");
                return;
            }
            // retrieve the actual body and content type
            $contentType = $this->_webserverContentTypes[$extension];
            $responseBody = file_get_contents($requestFile);
        } else {
            // the request cannot be resolved
            $this->_clientSendNotFound($client, $path);
            Log::info("webclient {$peer} request {$path} was not found");
            return;
        }


        // apply the requested compression to the body
        if($responseEncoding=='gzip') {
            $responseBody = gzencode($responseBody);
        } elseif($responseEncoding=='deflate') {
            $responseBody = gzdeflate($responseBody);
        }

        // compile response headers
        $responseHeader[] = 'HTTP/1.1 200 OK';
        $responseHeader[] = 'Date: '.date(DATE_RFC822);
        $responseHeader[] = 'Server: ClusterStatsDaemon';
        $responseHeader[] = 'Connection: close';
        $responseHeader[] = 'Content-Type: '.$contentType;
        $responseHeader[] = 'Content-Length: '.strlen($responseBody);
        if($responseEncoding!=='identity') {
            $responseHeader[] = 'Content-Encoding: '.$responseEncoding;
        }

        $response = implode("\r\n",$responseHeader) . "\r\n\r\n" . $responseBody;

        // send the webpage to the client and close the connection
        fwrite($client, $response);
        fclose($client);
    }

    /**
     * Initializes the webserver and starts a listening socket
     */
    private function _initialize() {
        try {
            $context = stream_context_create();
            $protocol = 'tcp://';
            $transports = stream_get_transports();

            if(!Config::get('http_ssl_enabled')) {
                // ssl is disabled, do not try to initialize ssl subsystem
                Log::info('Webserver SSL support is disabled');
            } elseif(!in_array('ssl',$transports)) {
                Log::info('Warning: Webserver cannot use SSL. PHP is not configured to support it');
            } elseif( $this->_checkSslCertificate() ) {
                stream_context_set_option($context, 'ssl', 'local_cert', $this->_webserverPemFile);
                stream_context_set_option($context, 'ssl', 'passphrase', Config::get('http_ssl_passphrase'));
                stream_context_set_option($context, 'ssl', 'allow_self_signed', true);
                stream_context_set_option($context, 'ssl', 'verify_peer', false);
                $protocol = 'ssl://';
                Log::info('Webserver SSL support is enabled');
            } else {
                Log::info('Warning: Webserver cannot use SSL. Make sure the webroot is configured and server.pem exists');
            }

            $port = Config::get('http_port') ? Config::get('http_port') : 8888;
            $this->_webserverSocket = @stream_socket_server("{$protocol}0.0.0.0:{$port}", $errno, $errstr, STREAM_SERVER_BIND|STREAM_SERVER_LISTEN, $context);

            if($this->_webserverSocket===FALSE) {
                Log::error('error starting webserver: '.$errstr);
                die( 'ERROR starting webserver: '.$errstr );
            }

            // Timeout for socket operation 5 seconds
            stream_set_timeout( $this->_webserverSocket, 5 );

            Log::info("Webserver started and listening on port {$port}");
        } catch (Exception $e) {
            Log::error('Webserver failed to initialize: '.$e->getMessage());
        }
    }

    /**
     * Checks if a PEM ssl certificate exists and if missing tries to create a new certificate
     *
     * @return Bool true if the certificate was successfully found or created or false on failure
     */
    private function _checkSslCertificate() { //return false;
        try {
            if ( @file_exists( $this->_webserverPemFile ) ) {
                // certificate file was found, bail out
                return true;
            }
            // certificate entities
            $dn = array(
                "countryName" => "BE",
                "stateOrProvinceName" => "Antwerp",
                "localityName" => "Retie",
                "organizationName" => "Jeroen Vermeulen BVBA",
                "organizationalUnitName" => "Application Development",
                "commonName" => "clusterstats.jeroenvermeulen.eu",
                "emailAddress" => "info@jeroenvermeulen.eu"
            );

            // generate certificate
            $privateKey = openssl_pkey_new();
            $certificate = openssl_csr_new($dn, $privateKey);
            $certificate = openssl_csr_sign($certificate, null, $privateKey, 365 * 25);

            // generate PEM file
            $pemArray = array();
            openssl_x509_export($certificate, $pemArray[0]);
            openssl_pkey_export($privateKey, $pemArray[1], Config::get('http_ssl_passphrase'));
            $pem = implode($pemArray);
            return (false !== @file_put_contents($this->_webserverPemFile, $pem));
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Checks if the web client credentials are valid
     *
     * @param string $requestHeader The complete HTTP request header
     * @return bool Authentication was successful
     */
    private function _clientCheckCredentials($requestHeader) {
        // if no password was set, always return true
        if(is_null($this->_webserverUsername) && is_null($this->_webserverPassword)) {
            return true;
        }
        preg_match('/^authorization:\s*basic\s*(?P<credentials>.*?)\s*$/im', $requestHeader, $matches);
        if(isset($matches['credentials'])) {
            $credentialArray = explode(':', base64_decode($matches['credentials']));
            $username = isset($credentialArray[0]) ? $credentialArray[0] : '';
            $password = isset($credentialArray[1]) ? $credentialArray[1] : '';
            if($username==$this->_webserverUsername && $password==$this->_webserverPassword) {
                return true;
            }
        }
        return false;
    }

    /**
     * Sends a 401 unauthorized to the webclient
     *
     * @param resource $client The client webbrowser socket
     */
    private function _clientSendUnauthorized($client) {
        $responseBody[] = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">';
        $responseBody[] = '<html><head>';
        $responseBody[] = '<title>401 Authorization Required</title>';
        $responseBody[] = '</head><body>';
        $responseBody[] = '<h1>Authorization Required</h1>';
        $responseBody[] = '<p>This server could not verify that you';
        $responseBody[] = 'are authorized to access the document';
        $responseBody[] = 'requested.  Either you supplied the wrong';
        $responseBody[] = 'credentials (e.g., bad password), or your';
        $responseBody[] = 'browser doesn\'t understand how to supply';
        $responseBody[] = 'the credentials required.</p>';
        $responseBody[] = '</body></html>';
        $responseBody=implode("\r\n",$responseBody);

        $responseHeader[] = 'HTTP/1.1 401 Authorization Required';
        $responseHeader[] = 'Date: '.date(DATE_RFC822);
        $responseHeader[] = 'Server: WorkerManager';
        $responseHeader[] = 'WWW-Authenticate: Basic realm="'.(defined('APP_NAME') ? APP_NAME : 'Restricted Access').'"';
        $responseHeader[] = 'Connection: close';
        $responseHeader[] = 'Content-Type: text/html';
        $responseHeader[] = 'Content-Length: '.strlen($responseBody);

        $response = implode("\r\n",$responseHeader) . "\r\n\r\n" . $responseBody;
        fwrite($client, $response);
        fclose($client);
    }

    /**
     * Sends a 403 forbidden to the web client
     *
     * @param resource $client - The client web browser socket
     * @param string   $path   - Path the visitor tried to access
     */
    private function _clientSendForbidden($client, $path) {
        $responseBody[] = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">';
        $responseBody[] = '<html><head>';
        $responseBody[] = '<title>403 Forbidden</title>';
        $responseBody[] = '</head><body>';
        $responseBody[] = '<h1>Forbidden</h1>';
        $responseBody[] = '<p>You don\'t have permission to access '.$path.' on this server.</p>';
        $responseBody[] = '</body></html>';
        $responseBody=implode("\r\n",$responseBody);

        $responseHeader[] = 'HTTP/1.1 403 Forbidden';
        $responseHeader[] = 'Date: '.date(DATE_RFC822);
        $responseHeader[] = 'Server: WorkerManager';
        $responseHeader[] = 'Connection: close';
        $responseHeader[] = 'Content-Type: text/html';
        $responseHeader[] = 'Content-Length: '.strlen($responseBody);

        $response = implode("\r\n",$responseHeader) . "\r\n\r\n" . $responseBody;
        fwrite($client, $response);
        fclose($client);
    }

    /**
     * Sends a 404 not found to the web client
     *
     * @param resource $client - The client web browser socket
     * @param string   $path   - Path the visitor tried to access
     */
    private function _clientSendNotFound($client, $path) {
        $responseBody[] = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">';
        $responseBody[] = '<html><head>';
        $responseBody[] = '<title>404 Not Found</title>';
        $responseBody[] = '</head><body>';
        $responseBody[] = '<h1>Not Found</h1>';
        $responseBody[] = '<p>The requested URL '.$path.' was not found on this server.</p>';
        $responseBody[] = '</body></html>';
        $responseBody=implode("\r\n",$responseBody);

        $responseHeader[] = 'HTTP/1.1 404 Not Found';
        $responseHeader[] = 'Date: '.date(DATE_RFC822);
        $responseHeader[] = 'Server: WorkerManager';
        $responseHeader[] = 'Connection: close';
        $responseHeader[] = 'Content-Type: text/html';
        $responseHeader[] = 'Content-Length: '.strlen($responseBody);

        $response = implode("\r\n",$responseHeader) . "\r\n\r\n" . $responseBody;
        fwrite($client, $response);
        fclose($client);
    }

    /**
     * Sends a 500 internal server error to the webclient
     *
     * @param resource $client The client webbrowser socket
     */
    private function _clientSendInternalServerError($client) {
        $responseBody[] = '<!DOCTYPE HTML PUBLIC "-//IETF//DTD HTML 2.0//EN">';
        $responseBody[] = '<html><head>';
        $responseBody[] = '<title>500 Internal Server Error</title>';
        $responseBody[] = '</head><body>';
        $responseBody[] = '<h1>Internal Server Error</h1>';
        $responseBody[] = '<p>The server encountered an internal error or';
        $responseBody[] = 'misconfiguration and was unable to complete';
        $responseBody[] = 'your request.</p>';
        $responseBody[] = '<p>Please contact the server administrator ';
        $responseBody[] = 'and inform them of the time the error occurred,';
        $responseBody[] = 'and anything you might have done that may have';
        $responseBody[] = 'caused the error.</p>';
        $responseBody[] = '</body></html>';
        $responseBody=implode("\r\n",$responseBody);

        $responseHeader[] = 'HTTP/1.1 500 Internal Server Error';
        $responseHeader[] = 'Date: '.date(DATE_RFC822);
        $responseHeader[] = 'Server: WorkerManager';
        $responseHeader[] = 'Connection: close';
        $responseHeader[] = 'Content-Type: text/html';
        $responseHeader[] = 'Content-Length: '.strlen($responseBody);

        $response = implode("\r\n",$responseHeader) . "\r\n\r\n" . $responseBody;
        fwrite($client, $response);
        fclose($client);
    }
}
