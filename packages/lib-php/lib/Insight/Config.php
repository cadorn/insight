<?php

require_once('Insight/Util.php');

class Insight_Config
{
    const PACKAGE_META_URI = 'http://registry.pinf.org/cadorn.org/insight/@meta/package/0';
    const CONFIG_META_URI = 'http://registry.pinf.org/cadorn.org/insight/@meta/config/0';
    
    protected $defaultConfig;
    
    protected $file = null;

    /**
     * @insight filter = on
     */
    protected $config = null;


    function __construct() {
        $this->defaultConfig = array(
            "implements" => array(
                self::CONFIG_META_URI => array(
                    "server" => array(
                        "path" => "/"
                    ),
                    "paths" => array(
                        "./" => "allow",
                        "./credentials.json" => "deny"
                    ),
                    "targets" => array(
                        "controller" => array(
                            "implements" => "http://registry.pinf.org/cadorn.org/insight/@meta/receiver/insight/controller/0",
                            "api" => "Insight/Plugin/Controller"
                        ),
                        "package" => array(
                            "implements" => "http://registry.pinf.org/cadorn.org/insight/@meta/receiver/insight/package/0",
                            "api" => "Insight/Plugin/Package"
                        ),
                        "console" => array(
                            "implements" => "http://registry.pinf.org/cadorn.org/insight/@meta/receiver/console/page/0",
                            "api" => "Insight/Plugin/Page"
                        ),
                        "request" => array(
                            "implements" => "http://registry.pinf.org/cadorn.org/insight/@meta/receiver/console/request/0",
                            "api" => "Insight/Plugin/Request"
                        )
                    ),
                    "renderers" => array(
                        "insight" => array(
                            "uid" => "http://registry.pinf.org/cadorn.org/renderers/packages/insight/0"
                        ),
                        "php" => array(
                            "uid" => "http://registry.pinf.org/cadorn.org/renderers/packages/php/0"
                        )
                    )
                )
            )
        );
    }

    public function loadFromFile($file, $additionalConfig) {
        if(!file_exists($file)) {
            throw new Exception('Config file not found at: ' . $file);
        }
        if(!is_readable($file)) {
            throw new Exception('Config file not readable at: ' . $file);
        }
        $this->file = $file;
        $this->config = $this->normalizeConfig($this->defaultConfig);
        if($additionalConfig && is_array($additionalConfig)) {
            $this->config = Insight_Util::array_merge($this->config, $this->normalizeConfig($additionalConfig));
        }
        $this->loadConfig($this->file);
        $this->loadConfig(str_replace(".json", ".local.json", $this->file));
        $this->loadCredentials(dirname($this->file) . DIRECTORY_SEPARATOR . 'credentials.json');
        $this->loadCredentials(dirname($this->file) . DIRECTORY_SEPARATOR . 'credentials.local.json');
        $this->validate();
    }

    protected function loadConfig($file) {
        if(!file_exists($file)) {
            return false;
        }
        try {
            $json = json_decode(file_get_contents($file), true);
            if(!$json) {
                throw new Exception();
            }
        } catch(Exception $e) {
            throw new Exception('Error (' . $this->getJsonError() . ') parsing JSON file: ' . $file);
        }
        $json = $this->normalizeConfig($json);
        $this->config = Insight_Util::array_merge($this->config, $json);
        return true;
    }
    
    protected function loadCredentials($file) {
        if(!file_exists($file)) {
            return false;
        }
        try {
            $credentials = json_decode(file_get_contents($file), true);
            if(!$credentials) {
                throw new Exception();
            }
        } catch(Exception $e) {
            throw new Exception('Error (' . $this->getJsonError() . ') parsing JSON file: ' . $file);
        }
        $credentials = $this->normalizeCredentials($credentials);
        if(isset($credentials[self::CONFIG_META_URI])) {
            $this->config['implements'][self::CONFIG_META_URI] = Insight_Util::array_merge($this->config['implements'][self::CONFIG_META_URI], $credentials[self::CONFIG_META_URI]);
        }
        return true;
    }

    protected function getJsonError() {
        switch(json_last_error()) {
            case JSON_ERROR_DEPTH:
                return 'Maximum stack depth exceeded';
            case JSON_ERROR_CTRL_CHAR:
                return 'Unexpected control character found';
            case JSON_ERROR_SYNTAX:
                return 'Syntax error, malformed JSON';
            case JSON_ERROR_NONE:
                return 'No errors';
        }
    }

    private function normalizeConfig($config) {
        
        if(isset($config['implements'])) {
            foreach( $config['implements'] as $key => $info ) {
                if(substr($key,0,5)!='http:') {
                    $config['implements']['http://registry.pinf.org/' . $key] = $info;
                    unset($config['implements'][$key]);
                }
            }
        }

        foreach(array('targets', 'renderers') as $prop1) {
            if(isset($config['implements'][self::CONFIG_META_URI][$prop1])) {
                foreach( $config['implements'][self::CONFIG_META_URI][$prop1] as $key => $info ) {
                    foreach(array('implements', 'uid') as $prop2) {
                        if(isset($info[$prop2]) && substr($info[$prop2],0,5)!='http:') {
                            $config[$prop2][self::CONFIG_META_URI][$prop1][$key][$prop2] = 
                                'http://registry.pinf.org/' . $config['implements'][self::CONFIG_META_URI][$prop1][$key][$prop2];
                        }
                    }
                }
            }
        }
        
        if(isset($config['implements'][self::CONFIG_META_URI]['paths'])) {
            $paths = array();
            foreach( $config['implements'][self::CONFIG_META_URI]['paths'] as $path => $instruction ) {
                if(substr($path, 0, 2)=="./") {
                    $paths[realpath( dirname($this->file) . DIRECTORY_SEPARATOR . substr($path,2) )] = $instruction;
                } else {
                    $paths[realpath($path)] = $instruction;
                }
            }
            // sort alphabetically from longest to shortest
            krsort($paths);
            $config['implements'][self::CONFIG_META_URI]['paths'] = $paths;
        }

        if(isset($config['implements'][self::CONFIG_META_URI]['cache']) &&
           isset($config['implements'][self::CONFIG_META_URI]['cache']['path'])) {
            $path = $config['implements'][self::CONFIG_META_URI]['cache']['path'];
            if(substr($path, 0, 2)=="./") {
                $normalizedPath = realpath( dirname($this->file) . DIRECTORY_SEPARATOR . substr($path,2) );
                if(!$normalizedPath) {
                    $normalizedPath = realpath(dirname($this->file)) . DIRECTORY_SEPARATOR . substr($path,2);
                }
                $config['implements'][self::CONFIG_META_URI]['cache']['path'] = $normalizedPath;
            }
        }

        return $config;
    }

    private function normalizeCredentials($config) {
        foreach( $config as $key => $info ) {
            if(substr($key,0,5)!='http:') {
                $config['http://registry.pinf.org/' . $key] = $info;
                unset($config[$key]);
            }
        }
        return $config;
    }
    
    private function validate() {

        if(!isset($this->config['uid'])) {
            throw new Exception('"uid" config property not set in ' . $this->file);
        }

        if(isset($this->config['implements'][self::PACKAGE_META_URI])) {
            $config = $this->config['implements'][self::PACKAGE_META_URI];
            // TODO: validate
        }
        
        $config = $this->config['implements'][self::CONFIG_META_URI];
        
        $CONFIG_META_URI = str_replace("http://registry.pinf.org/", "", self::CONFIG_META_URI);
        
        if(!isset($config['allow'])) {
            throw new Exception('"allow" config property not set for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }
        if(!isset($config['allow']['ips'])) {
            throw new Exception('"allow.ips" config property not set for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }
        if(!isset($config['allow']['authkeys'])) {
            throw new Exception('"allow.authkeys" config property not set for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }
        if(!isset($config['server'])) {
            throw new Exception('"server" config property not set for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }
        if(isset($config['server']) && !isset($config['server']['path'])) {
            throw new Exception('"server.path" config property not set for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }            
        if(substr($config['server']['path'], 0, 1)!="/" && substr($config['server']['path'], 0, 2)!="./") {
            throw new Exception('"server.path" config property must begin with a forward slash for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }            
        if(!isset($config['targets'])) {
            throw new Exception('"targets" config property not set for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }
        if(!isset($config['renderers'])) {
            throw new Exception('"renderers" config property not set for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }
        if(isset($config['cache']) && isset($config['cache']['path']) && !file_exists($config['cache']['path'])) {
            throw new Exception('"cache.path" [' . $config['cache']['path'] . '] does not exist for ' . $CONFIG_META_URI . '  in ' . $this->file);
        }
    }
    
    public function getPackageId() {
        return $this->config['uid'];
    }

    public function getPackageInfo() {        
        $info = array('links'=>array('quick'=>array()));
        if(isset($this->config['name'])) {
            $info['name'] = $this->config['name'];
        }
        if(isset($this->config['description'])) {
            $info['description'] = $this->config['description'];
        }
        if(isset($this->config['homepage'])) {
            $info['links']['quick']['Homepage'] = $this->config['homepage'];
        }
        if(isset($this->config['bugs'])) {
            $info['links']['quick']['Bugs'] = $this->config['bugs'];
        }
        if(isset($this->config['implements'][self::PACKAGE_META_URI])) {
            $info = Insight_Util::array_merge($info, $this->config['implements'][self::PACKAGE_META_URI]);
        }
        return $info;
    }
    
    public function getServerInfo() {
        if(!isset($this->config['implements'][self::CONFIG_META_URI]['server'])) {
            return false;
        }
        return $this->config['implements'][self::CONFIG_META_URI]['server'];
    }

    public function getPaths() {
        if(!isset($this->config['implements'][self::CONFIG_META_URI]['paths'])) {
            return false;
        }
        return $this->config['implements'][self::CONFIG_META_URI]['paths'];
    }

    public function getPlugins() {
        if(!isset($this->config['implements'][self::CONFIG_META_URI]['plugins'])) {
            return false;
        }
        return $this->config['implements'][self::CONFIG_META_URI]['plugins'];
    }

    public function getTargets() {
        if(!isset($this->config['implements'][self::CONFIG_META_URI]['targets'])) {
            return false;
        }
        return $this->config['implements'][self::CONFIG_META_URI]['targets'];
    }
    
    public function getRenderers() {
        if(!isset($this->config['implements'][self::CONFIG_META_URI]['renderers'])) {
            return false;
        }
        return $this->config['implements'][self::CONFIG_META_URI]['renderers'];
    }

    public function getPluginInfo($name) {
        $plugins = $this->getPlugins();
        if(!isset($plugins[$name])) {
            throw new Exception('"plugins.'.$name.'" config property not set');
        }
        return $plugins[$name];
    }

    public function getTargetInfo($name) {
        $targets = $this->getTargets();
        if(!isset($targets[$name])) {
            throw new Exception('"targets.'.$name.'" config property not set');
        }
        return $targets[$name];
    }

    public function getRendererInfo($name) {
        $renderers = $this->getRenderers();
        if(!isset($renderers[$name])) {
            throw new Exception('"renderers.'.$name.'" config property not set');
        }
        return $renderers[$name];
    }

    public function getAuthkeys() {
        return $this->config['implements'][self::CONFIG_META_URI]['allow']['authkeys'];
    }

    public function getIPs() {
        return $this->config['implements'][self::CONFIG_META_URI]['allow']['ips'];
    }
    
    public function getEncoderOptions() {
        if(!isset($this->config['implements'][self::CONFIG_META_URI]['options']) ||
           !isset($this->config['implements'][self::CONFIG_META_URI]['options']['encoder'])) {
            return array();
        }
        return $this->config['implements'][self::CONFIG_META_URI]['options']['encoder'];
    }

    public function getCachePath($basePathOnly=false) {
        $nsPath = 'cadorn.org' . DIRECTORY_SEPARATOR . 'insight';
        if(!isset($this->config['implements'][self::CONFIG_META_URI]['cache']) ||
           !isset($this->config['implements'][self::CONFIG_META_URI]['cache']['path'])) {
            // check if we have a central PINF cache path
            // NOTE: Assumes we are running on a UNIX filesystem
            $path = '/pinf';
            if(is_dir($path)) {
                return ($basePathOnly)?$path:$path . '/cache/cadorn.org/insight';
            }
            return sys_get_temp_dir() . DIRECTORY_SEPARATOR . $nsPath;
        }
        return ($basePathOnly)?
                $this->config['implements'][self::CONFIG_META_URI]['cache']['path']:
                $this->config['implements'][self::CONFIG_META_URI]['cache']['path'] . DIRECTORY_SEPARATOR . $nsPath;
    }
}
