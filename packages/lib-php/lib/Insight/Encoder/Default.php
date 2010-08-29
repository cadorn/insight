<?php

require_once('Insight/Util.php');
require_once('Zend/Reflection/Class.php');

class Insight_Encoder_Default {

    const UNDEFINED = '_U_N_D_E_F_I_N_E_D_';

    protected $options = array('maxDepth' => 5,
                               'maxObjectDepth' => 3,
                               'maxArrayDepth' => 3,
                               'maxArrayLength' => 25,
                               'maxObjectLength' => 25,
                               'includeLanguageMeta' => true,
                               'treatArrayMapAsDictionary' => false);

    /**
     * @insight filter = on
     */
    protected $_origin = self::UNDEFINED;
    
    /**
     * @insight filter = on
     */
    protected $_meta = null;

    /**
     * @insight filter = on
     */
    protected $_instances = array();


    public function setOption($name, $value)
    {
        $this->options[$name] = $value;
    }    

    public function setOptions($options)
    {
        if(count($diff = array_diff(array_keys($options), array_keys($this->options)))>0) {
            throw new Exception('Unknown options: ' . implode(',', $diff));
        }
        $this->options = Insight_Util::array_merge($this->options, $options);
    }    

    public function setOrigin($variable)
    {
        $this->_origin = $variable;
        
        // reset some variables
        $this->_instances = array();

        return true;
    }

    public function setMeta($meta)
    {
        $this->_meta = $meta;
    }

    public function getOption($name) {
        // check for option in meta first, then fall back to default options
        if(isset($this->_meta['encoder.' . $name])) {
            return $this->_meta['encoder.' . $name];
        } else
        if(isset($this->options[$name])) {
            return $this->options[$name];
        } else
        if($name=='depthExtend') {
            return 0;
        } else
        if($name=='depthNoLimit') {
            return false;
        }
        return null;
    }

    public function encode($data=self::UNDEFINED, $meta=self::UNDEFINED)
    {
        if($data!==self::UNDEFINED) {
            $this->setOrigin($data);
        }

        if($meta!==self::UNDEFINED) {
            $this->setMeta($meta);
        }

        $graph = array();
        
        if($this->_origin!==self::UNDEFINED) {
            $graph['origin'] = $this->_encodeVariable($this->_origin);
        }
        
        if($this->_instances) {
            foreach( $this->_instances as $key => $value ) {
                $graph['instances'][$key] = $value[1];
            }
        }

        if($this->getOption('includeLanguageMeta')) {
            if(!$this->_meta) {
                $this->_meta = array();
            }
            if(!isset($this->_meta['lang.id'])) {
                $this->_meta['lang.id'] = 'registry.pinf.org/cadorn.org/github/renderers/packages/php/master';
            }
        }

        // remove encoder options
        foreach( $this->_meta as $name => $value ) {
            if($name=="encoder" || substr($name, 0, 8)=="encoder.") {
                unset($this->_meta[$name]);
            }
        }

        return array(json_encode($graph), ($this->_meta)?$this->_meta:false);
    }


    protected function _encodeVariable($Variable, $ObjectDepth = 1, $ArrayDepth = 1, $MaxDepth = 1)
    {
/*        
        if($Variable===self::UNDEFINED) {
            $var = array('type'=>'constant', 'constant'=>'undefined');
            if($this->options['includeLanguageMeta']) {
                $var['lang.type'] = 'undefined';
            }
            return $var;
        } else
*/

        if(is_null($Variable)) {
            $var = array('type'=>'constant', 'constant'=>'null');
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'null';
            }
        } else
        if(is_bool($Variable)) {
            $var = array('type'=>'constant', 'constant'=>($Variable)?'true':'false');
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'boolean';
            }
        } else
        if(is_int($Variable)) {
            $var = array('type'=>'text', 'text'=>(string)$Variable);
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'integer';
            }
        } else
        if(is_float($Variable)) {
            $var = array('type'=>'text', 'text'=>(string)$Variable);
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'float';
            }
        } else
        if(is_object($Variable)) {
            $sub = $this->_encodeInstance($Variable, $ObjectDepth, $ArrayDepth, $MaxDepth);
            $var = array('type'=>'reference', 'reference'=> $sub['value']);
            if(isset($sub['meta'])) {
                $var = Insight_Util::array_merge($var, $sub['meta']);
            }
        } else
        if(is_array($Variable)) {
            $sub = null;
            // Check if we have an indexed array (list) or an associative array (map)
            if(Insight_Util::is_list($Variable)) {
                $sub = $this->_encodeArray($Variable, $ObjectDepth, $ArrayDepth, $MaxDepth);
                $var = array('type'=>'array', 'array'=> $sub['value']);
            } else
            if($this->getOption('treatArrayMapAsDictionary')) {
                $sub = $this->_encodeAssociativeArray($Variable, $ObjectDepth, $ArrayDepth, $MaxDepth);
                $var = array('type'=>'dictionary', 'dictionary'=> isset($sub['value'])?$sub['value']:false);
            } else {
                $sub = $this->_encodeAssociativeArray($Variable, $ObjectDepth, $ArrayDepth, $MaxDepth);
                $var = array('type'=>'map', 'map'=> isset($sub['value'])?$sub['value']:false);
            }
            if(isset($sub['meta'])) {
                $var = Insight_Util::array_merge($var, $sub['meta']);
            }
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'array';
            }
        } else
        if(is_resource($Variable)) {
            // TODO: Try and get more info about resource
            $var = array('type'=>'text', 'text'=>(string)$Variable);
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'resource';
            }
        } else
        if(is_string($Variable)) {
            $var = array('type'=>'text');
            // TODO: Add info about encoding
            if(self::is_utf8($Variable)) {
                $var['text'] = $Variable;
            } else {
                $var['text'] = utf8_encode($Variable);
            }
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'string';
            }
        } else {
            $var = array('type'=>'text', 'text'=>(string)$Variable);
            if($this->getOption('includeLanguageMeta')) {
                $var['lang.type'] = 'unknown';
            }
        }        
        return $var;
    }
    
    protected function _isObjectMemberFiltered($ClassName, $MemberName) {
        $filter = $this->getOption('filter');
        if(!isset($filter['classes']) || !is_array($filter['classes'])) {
            return false;
        }
        if(!isset($filter['classes'][$ClassName]) || !is_array($filter['classes'][$ClassName])) {
            return false;
        }
        return in_array($MemberName, $filter['classes'][$ClassName]);
    }
    
    protected function _getInstanceID($Object)
    {
        foreach( $this->_instances as $key => $instance ) {
            if($instance[0]===$Object) {
                return $key;
            }
        }
        return null;
    }
    
    protected function _encodeInstance($Object, $ObjectDepth = 1, $ArrayDepth = 1, $MaxDepth = 1)
    {
        if(($ret=$this->_checkDepth('Object', $ObjectDepth, $MaxDepth))!==false) {
            return $ret;
        }

        $id = $this->_getInstanceID($Object);
        if($id!==null) {
            return array('value'=>$id);
        }

        $id = sizeof($this->_instances);
        $this->_instances[$id] = array($Object);
        $this->_instances[$id][1] = $this->_encodeObject($Object, $ObjectDepth, $ArrayDepth, $MaxDepth);
        
        return array('value'=>$id);
    }    
    
    protected function _checkDepth($Type, $TypeDepth, $MaxDepth) {

        $depthNoLimit = $this->getOption('depthNoLimit');
        if($depthNoLimit===true) {
            return false;
        }

        $depthExtend = $this->getOption('depthExtend');

        $MaxDepth -= $depthExtend;
        $TypeDepth -= $depthExtend;

        if ($MaxDepth > $this->getOption('maxDepth')) {
            return array(
                'value' => null,
                'meta' => array(
                    'encoder.trimmed' => true,
                    'encoder.notice' => 'Max Depth ('.$this->getOption('maxDepth').')'
                )
            );
        }
        if ($TypeDepth > $this->getOption('max' . $Type . 'Depth')) {
            return array(
                'meta' => array(
                    'encoder.trimmed' => true,
                    'encoder.notice' => 'Max ' . $Type . ' Depth ('.$this->getOption('max' . $Type . 'Depth').')'
                )
            );
        }
        return false;
    }
    
    protected function _encodeAssociativeArray($Variable, $ObjectDepth = 1, $ArrayDepth = 1, $MaxDepth = 1)
    {
        if(($ret=$this->_checkDepth('Array', $ArrayDepth, $MaxDepth))!==false) {
            return $ret;
        }

        $index = 0;
        $maxLength = $this->getOption('maxArrayLength');
        $depthNoLimit = $this->getOption('depthNoLimit');
        foreach ($Variable as $key => $val) {
          
          // Encoding the $GLOBALS PHP array causes an infinite loop
          // if the recursion is not reset here as it contains
          // a reference to itself. This is the only way I have come up
          // with to stop infinite recursion in this case.
          if($key=='GLOBALS'
             && is_array($val)
             && array_key_exists('GLOBALS',$val)) {
            $val['GLOBALS'] = '** Recursion (GLOBALS) **';
          }

          if($this->getOption('treatArrayMapAsDictionary')) {
              $return[$key] = $this->_encodeVariable($val, 1, $ArrayDepth + 1);
          } else {
              $return[] = array($this->_encodeVariable($key), $this->_encodeVariable($val, 1, $ArrayDepth + 1, $MaxDepth + 1));
          }

          $index++;
          if($index>=$maxLength && $depthNoLimit!==true) {
              if($this->getOption('treatArrayMapAsDictionary')) {
                  $return['...'] = array(
                    'encoder.trimmed' => true,
                    'encoder.notice' => 'Max Array Length ('.$this->getOption('maxArrayLength').')'
                  );
              } else {
                  $return[] = array(array(
                    'encoder.trimmed' => true,
                    'encoder.notice' => 'Max Array Length ('.$this->getOption('maxArrayLength').')'
                  ), array(
                    'encoder.trimmed' => true,
                    'encoder.notice' => 'Max Array Length ('.$this->getOption('maxArrayLength').')'
                  ));
              }
              break;
          }
        }
        return array('value'=>$return);
    }

    protected function _encodeArray($Variable, $ObjectDepth = 1, $ArrayDepth = 1, $MaxDepth = 1)
    {
        if(($ret=$this->_checkDepth('Array', $ArrayDepth, $MaxDepth))!==false) {
            return $ret;
        }

        $items = array();
        $index = 0;
        $maxLength = $this->getOption('maxArrayLength');
        $depthNoLimit = $this->getOption('depthNoLimit');
        foreach ($Variable as $val) {
          $items[] = $this->_encodeVariable($val, 1, $ArrayDepth + 1, $MaxDepth + 1);
          $index++;
          if($index>=$maxLength && $depthNoLimit!==true) {
              $items[] = array(
                'encoder.trimmed' => true,
                'encoder.notice' => 'Max Array Length ('.$this->getOption('maxArrayLength').')'
              );
              break;
          }
        }
        return array('value'=>$items);
    }
    
    
    protected function _encodeObject($Object, $ObjectDepth = 1, $ArrayDepth = 1, $MaxDepth = 1)
    {
        $return = array('type'=>'dictionary', 'dictionary'=>array());

        $class = get_class($Object);
        if($this->getOption('includeLanguageMeta')) {
            if($Object instanceof Exception) {
                $return['lang.type'] = 'exception';
            } else {
                $return['lang.type'] = 'object';
            }
            $return['lang.class'] = $class;
        }

        $classAnnotations = $this->_getClassAnnotations($class);

        $properties = $this->_getClassProperties($class);
        $reflectionClass = new ReflectionClass($class);  
        
        if($this->getOption('includeLanguageMeta')) {
            $return['lang.file'] = $reflectionClass->getFileName();
        }

        $maxLength = $this->getOption('maxObjectLength');
        $depthNoLimit = $this->getOption('depthNoLimit');
        $maxLengthReached = false;
        
        $members = (array)$Object;
        foreach( $properties as $name => $property ) {
          
          if($name=='__insight_tpl_id') {
              $return['tpl.id'] = $property->getValue($Object);
              continue;
          }
          
          if(count($return['dictionary'])>$maxLength && $depthNoLimit!==true) {
              $maxLengthReached = true;
              break;
          }
          
          $info = array();
          $info['name'] = $name;
          
          $raw_name = $name;
          if($property->isStatic()) {
            $info['static'] = 1;
          }
          if($property->isPublic()) {
            $info['visibility'] = 'public';
          } else
          if($property->isPrivate()) {
            $info['visibility'] = 'private';
            $raw_name = "\0".$class."\0".$raw_name;
          } else
          if($property->isProtected()) {
            $info['visibility'] = 'protected';
            $raw_name = "\0".'*'."\0".$raw_name;
          }

          if(isset($classAnnotations['$'.$name])
             && isset($classAnnotations['$'.$name]['filter'])
             && $classAnnotations['$'.$name]['filter']=='on') {
                   
              $info['notice'] = 'Trimmed by annotation filter';
          } else
          if($this->_isObjectMemberFiltered($class, $name)) {
                   
              $info['notice'] = 'Trimmed by registered filters';
          }

          if(method_exists($property, 'setAccessible')) {
              $property->setAccessible(true);
          }

          if(isset($info['notice'])) {

              $info['trimmed'] = true;

              try {
                      
                  $info['value'] = $this->_trimVariable($property->getValue($Object));
                  
              } catch(ReflectionException $e) {
                  $info['value'] =  $this->_trimVariable(self::UNDEFINED);
                  $info['notice'] .= ', Need PHP 5.3 to get value';
              }

          } else {
              
            $value = self::UNDEFINED;

            if(array_key_exists($raw_name,$members)) {
//            if(array_key_exists($raw_name,$members)
 //              && !$property->isStatic()) {

                $value = $members[$raw_name];

            } else {
              try {
                  $value = $property->getValue($Object);
              } catch(ReflectionException $e) {
                  $info['value'] =  $this->_trimVariable(self::UNDEFINED);
                  $info['notice'] = 'Need PHP 5.3 to get value';
              }
            }

            if($value!==self::UNDEFINED) {
                // NOTE: This is a bit of a hack but it works for now
                if($Object instanceof Exception && $name=='trace' && $this->getOption('exception.traceOffset')!==null) {
                  $offset = $this->getOption('exception.traceOffset');
                  if($offset==-1) {
                      array_unshift($value, array(
                          'file' => $Object->getFile(),
                          'line' =>  $Object->getLine(),
                          'type' => 'throw',
                          'class' => $class,
                          'args' => array(
                              $Object->getMessage()
                          )
                      ));
                  } else
                  if($offset>0) {
                      array_splice($value, 0, $offset);
                  }
                }
            }
            
            if($value!==self::UNDEFINED) {
                $info['value'] = $this->_encodeVariable($value, $ObjectDepth + 1, 1, $MaxDepth + 1);
            }
          }
          
          $return['dictionary'][$info['name']] = $info['value'];
          if(isset($info['notice'])) {
              $return['dictionary'][$info['name']]['encoder.notice'] = $info['notice'];
          }
          if(isset($info['trimmed'])) {
              $return['dictionary'][$info['name']]['encoder.trimmed'] = $info['trimmed'];
          }
          if($this->getOption('includeLanguageMeta')) {
              if(isset($info['visibility'])) {
                  $return['dictionary'][$info['name']]['lang.visibility'] = $info['visibility'];
              }
              if(isset($info['static'])) {
                  $return['dictionary'][$info['name']]['lang.static'] = $info['static'];
              }
          }
//          $return['members'][] = $info;
        }
        
        if(!$maxLengthReached) {
            // Include all members that are not defined in the class
            // but exist in the object
            foreach( $members as $name => $value ) {
              
              if ($name{0} == "\0") {
                $parts = explode("\0", $name);
                $name = $parts[2];
              }

              if(count($return['dictionary'])>$maxLength && $depthNoLimit!==true) {
                  $maxLengthReached = true;
                  break;
              }
              
              if(!isset($properties[$name])) {
                
                $info = array();
                $info['undeclared'] = 1;
                $info['name'] = $name;
    
                if(isset($classAnnotations['$'.$name])
                   && isset($classAnnotations['$'.$name]['filter'])
                   && $classAnnotations['$'.$name]['filter']=='on') {
                           
                    $info['notice'] = 'Trimmed by annotation filter';
                } else
                if($this->_isObjectMemberFiltered($class, $name)) {
                           
                    $info['notice'] = 'Trimmed by registered filters';
                }
    
                if(isset($info['notice'])) {
                    $info['trimmed'] = true;
                    $info['value'] = $this->_trimVariable($value);
                } else {
                    $info['value'] = $this->_encodeVariable($value, $ObjectDepth + 1, 1, $MaxDepth + 1);
                }
    
                $return['dictionary'][$info['name']] = $info['value'];
                if($this->getOption('includeLanguageMeta')) {
                    $return['dictionary'][$info['name']]['lang.undeclared'] = 1;
                }
                if(isset($info['notice'])) {
                    $return['dictionary'][$info['name']]['encoder.notice'] = $info['notice'];
                }
                if(isset($info['trimmed'])) {
                    $return['dictionary'][$info['name']]['encoder.trimmed'] = $info['trimmed'];
                }
    
    //            $return['members'][] = $info;    
              }
            }
        }
        
        if($maxLengthReached) {
            unset($return['dictionary'][array_pop(array_keys($return['dictionary']))]);
            $return['dictionary']['...'] = array(
              'encoder.trimmed' => true,
              'encoder.notice' => 'Max Object Length ('.$this->getOption('maxObjectLength').')'
            );
        }

        return $return;
    }

    protected function _trimVariable($var, $length=20)
    {
        if(is_null($var)) {
            $text = 'NULL';
        } else
        if(is_bool($var)) {
            $text = ($var)?'TRUE':'FALSE';
        } else
        if(is_int($var) || is_float($var) || is_double($var)) {
            $text = $this->_trimString((string)$var, $length);
        } else
        if(is_object($var)) {
            $text = $this->_trimString(get_class($var), $length);
        } else
        if(is_array($var)) {
            $text = $this->_trimString(serialize($var), $length);
        } else
        if(is_resource($var)) {
            $text = $this->_trimString('' . $var);
        } else
        if(is_string($var)) {
            $text = $this->_trimString($var, $length);
        } else {
            $text = $this->_trimString($var, $length);
        }
        return array(
            'type' => 'text',
            'text' => $text
        );
    }
    
    protected function _trimString($string, $length=20)
    {
        if(strlen($string)<=$length+3) {
            return $string;
        }
        return substr($string, 0, $length) . '...';
    }    
    
    protected function _getClassProperties($class)
    {
        $reflectionClass = new ReflectionClass($class);  
                
        $properties = array();

        // Get parent properties first
        if($parent = $reflectionClass->getParentClass()) {
            $properties = $this->_getClassProperties($parent->getName());
        }
        
        foreach( $reflectionClass->getProperties() as $property) {
          $properties[$property->getName()] = $property;
        }
        
        return $properties;
    }
    
    protected function _getClassAnnotations($class)
    {
        $annotations = array();
        
        // TODO: Go up to parent classes (let subclasses override tags from parent classes)
        
        $reflectionClass = new Zend_Reflection_Class($class);
        
        foreach( $reflectionClass->getProperties() as $property ) {
            
            $docblock = $property->getDocComment();
            if($docblock) {
                
                $tags = $docblock->getTags('insight');
                if($tags) {
                    foreach($tags as $tag) {
                       
                       list($name, $value) = $this->_parseAnnotationTag($tag);
                       
                       $annotations['$'.$property->getName()][$name] = $value;
                    }
                }
            }
        }
        
        return $annotations;
    }
    
    protected function _parseAnnotationTag($tag) {
        
        if(!preg_match_all('/^([^)\s]*?)\s*=\s*(.*?)$/si', $tag->getDescription(), $m)) {
            Insight_Annotator::setVariables(array('tag'=>$tag));
            throw new Exception('Tag format not valid!');
        }
        
        return array($m[1][0], $m[2][0]);
    }

 
    /**
     * is_utf8 - Checks if a string complies with UTF-8 encoding
     * 
     * @see http://us2.php.net/mb_detect_encoding#85294
     */
    protected static function is_utf8($str) {
        $c=0; $b=0;
        $bits=0;
        $len=strlen($str);
        for($i=0; $i<$len; $i++){
            $c=ord($str[$i]);
            if($c > 128){
                if(($c >= 254)) return false;
                elseif($c >= 252) $bits=6;
                elseif($c >= 248) $bits=5;
                elseif($c >= 240) $bits=4;
                elseif($c >= 224) $bits=3;
                elseif($c >= 192) $bits=2;
                else return false;
                if(($i+$bits) > $len) return false;
                while($bits > 1){
                    $i++;
                    $b=ord($str[$i]);
                    if($b < 128 || $b > 191) return false;
                    $bits--;
                }
            }
        }
        return true;
    }    
}
