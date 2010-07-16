<?php

require_once('Insight/Plugin/Tester.php');
require_once('Insight/Plugin/FileViewer.php');

class Insight_Server
{
    
    private $config = null;
    private $plugins = array();

    function __construct() {
        // TODO: Load plugins dynamically based on server request
        $this->registerPlugin(new Insight_Plugin_Tester());
        $this->registerPlugin(new Insight_Plugin_FileViewer());
    }
    
    public function setConfig($config) {
        $this->config = $config;
    }
    
    public function registerPlugin($plugin) {
        $this->plugins[strtolower(get_class($plugin))] = $plugin;
    }

    public function getUrl() {
        $info = $this->config->getServerInfo();
        $path = $info['path'];
        if(substr($path, 0, 2)=="./") {
            $pathInfo = parse_url("http://domain.com" . $_SERVER['REQUEST_URI']);
            $pathParts = explode("/", $pathInfo['path']);
            // trim filename if applicable
            if(substr($_SERVER['REQUEST_URI'], -1,1)!='/') {
                array_pop($pathParts);
            }
            $path = implode("/", $pathParts) . '/' . substr($path, 2);
        }
        // TODO: Use https if $info['secure'] == true
        return 'http://' . $_SERVER['HTTP_HOST'] . $path;
    }
    
    public function getPath() {
        $urlInfo = parse_url($this->getUrl());
        return $urlInfo['path'];
    }

    public function listen() {
/*
        $path = $this->getPath();

        if(substr($_SERVER['REQUEST_URI'], 0, strlen($path))!=$path) {
            // Not an insight server request
            return;
        }
*/
        // TODO: Use wildfire headers to check for server request in future?
        if(Insight_Server::getRequestHeader('x-insight')!='serve') {
            return;
        }

        try {
            $response = $this->respond(json_decode($_POST['payload'], true));

            if(!$response) {
                header("HTTP/1.0 204 No Content");
                header("Status: 204 No Content");
            } else {
                switch($response['type']) {
                    case 'error':
                        header("HTTP/1.0 " . $response['status']);
                        header("Status: " . $response['status']);
                    	break;
                    case 'json':
                        header("Content-Type: application/json");
                        echo(json_encode($response['data']));
                        break;
                    default:
                        echo($response['data']);
                        break;
                }
            }
        } catch(Exception $e) {
            header("HTTP/1.0 500 Internal Server Error");
            header("Status: 500 Internal Server Error");

            echo($e->getMessage());

            // TODO: Log error to insight client
        }
        exit;
    }

    protected function respond($payload) {
        if(!$payload['target']) {
            throw new Exception('$payload.target not set');
        }
        if(!$payload['action']) {
            throw new Exception('$payload.action not set');
        }
        if(!isset($this->plugins[strtolower($payload['target'])])) {
            throw new Exception('$payload.target not found in $plugins');
        }
        $plugin = $this->plugins[strtolower($payload['target'])];
        return $plugin->respond($this, $payload['action'], (isset($payload['args']))?$payload['args']:array() );
    }

    public function getRequestHeader($Name) {
        $headers = getallheaders();
        if(isset($headers[$Name])) {
            return $headers[$Name];
        } else
        // just in case headers got lower-cased in transport
        if(isset($headers[strtolower($Name)])) {
            return $headers[strtolower($Name)];
        }
        return false;
    }
    
    public function canServeFile($file) {
        $file = realpath($file);
        if(!file_exists($file)) {
            return false;
        }
        $paths = $this->config->getPaths();
        // find longest path match and look at instruction
        foreach( $paths as $path => $instruction ) {
            if(substr($file, 0, strlen($path))==$path) {
                if($instruction=="deny") {
                    return false;
                } else
                if($instruction=="allow") {
                    return $file;
                }
            }
        }
        return false;
    }
}