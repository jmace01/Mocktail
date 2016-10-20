<?php

/****************************************************************************************
 * 
 * Mocktail : A PHP light-weight PHP Mock library 
 * 
 * @author Jason Mace (github.com/jmace01)
 * @copyright 2016 by Jason Mace
 * 
 * 
 * How it works:
 *      The class reads in a PHP class file, finds the public interface of the class, and
 *      replaces it with a dummy class that has tracking, mocking, and spying features.
 * 
 * 
 * Limitations
 *      The class will only work if the file being read in contains a single PHP class.
 * 
 * 
 * Usage:
 *      //Create a new mock
 *      Mocktail::generateClassMock('./path/to/SomeClass.php');
 * 
 *      //Create and use the class normally
 *      $mock = new SomeClass();
 * 
 *      //Set return values
 *      Mocktail::setMethodReturnValues('SomeClass', 'someMethod', array(1,2,3));
 *      $mock->someMethod(); //will return 1
 *      $mock->someMethod(); //will return 2
 *      $mock->someMethod(); //will return 3
 * 
 *      //Get the number of times a method was called
 *      Mocktail::getGlobalMethodCount('SomeClass', 'someMethod'); //Will return 3
 * 
 *      //Reset the method call counter
 *      Mocktail::resetGlobalMethodCount('SomeClass', 'someMethod'); //reset on method counter
 *      Mocktail::resetAllGlobalCounts('SomeClass');                 //reset all counters
 * 
 *      //Spy on a method
 *      function spyFunction($params) { echo 'I was called!'; }
 *      Mocktail::setSpy('SomeClass', 'someMethod', 'spyFunction');
 *      $mock->someMethod(); //will print 'I was called!'
 * 
 ****************************************************************************************/
class Mocktail {
    
    private static function getCleanFile($file) {
        $contents = file_get_contents($file);
        //Clean up the file so comments and strings don't give false positives
        $cleaned = preg_replace(
            '((?# This part will find comments)(?:#|\\/\\/)[^\\r\\n]*|\\/\\*[\\s\\S]*?\\*\\/|(?# This part will find strings)(?<!\\\\)(\'|").*(?<!\\\\)(\\2))',
            '',
            $contents
        );
        return $contents;
    }
    
    
    private static function generateMemeberVariables($signatures) {
        $memberVariables = '';
        
        foreach ($signatures[0] as $key => $signature) {
            if (strtolower($signatures[2][$key]) == '__construct') {
                $memberVariables .= "private static \$_globalTimesCalledConstructor = 0;";
                $memberVariables .= "private static \$_spyConstructor = null;";
                continue;
            }
            
            $methodName = ucfirst($signatures[2][$key]);
            $memberVariables .= "private static \$_globalTimesCalled$methodName = 0;";
            $memberVariables .= "private static \$_valueNumber$methodName = 0;";
            $memberVariables .= "private static \$_values$methodName = array();";
            $memberVariables .= "private static \$_spy$methodName = null;";
        }
        
        return $memberVariables;
    }
    
    
    private static function generateMockMethods($signatures) {
        $mockedMethods = '';
        foreach ($signatures[0] as $key => $signature) {
            if (strtolower($signatures[2][$key]) == '__construct') {
                $mockedMethods .= $signature . '{';
                $mockedMethods .= 'self::$_globalTimesCalledConstructor++;';
                $mockedMethods .= 'if (self::$_spyConstructor != null){ call_user_func(self::$_spyConstructor); }';
                $mockedMethods .= '}';
                continue;
            }
            
            $methodName = ucfirst($signatures[2][$key]);
            
            $mockedMethods .= $signature . '{';
            $mockedMethods .=    'self::$_globalTimesCalled' . $methodName . '++;';
            $mockedMethods .= 'if (self::$_spy' . $methodName . ' != null){ call_user_func(self::$_spy' . $methodName . ', func_get_args()); }';
            $mockedMethods .= 'if (count(self::$_values' . $methodName . ') <= self::$_valueNumber' . $methodName . ')';
            $mockedMethods .=    'return null;';
            $mockedMethods .= 'else ';
            $mockedMethods .=    'return self::$_values' . $methodName . '[self::$_valueNumber' . $methodName . '++];';
            $mockedMethods .= '}';
        }
        return $mockedMethods;
    }
    
    
    private static function generateHelperMethods($signatures) {
        $helperMethods = '';
        $resetCode = '';
        foreach ($signatures[0] as $key => $signature) {
            if (strtolower($signatures[2][$key]) == '__construct') {
                $helperMethods .= 'public static function _getGlobalTimesCalledConstructor(){ return self::$_globalTimesCalledConstructor; }';
                $helperMethods .= 'public static function _resetGlobalTimesCalledConstructor(){ self::$_globalTimesCalledConstructor = 0; }';
                $helperMethods .= 'public static function _setConstructorSpy($spy){';
                $helperMethods .= 'if (!is_string($spy)) throw new Exception(\'Spy callback must be string name of function!\');';
                $helperMethods .= 'self::$_spyConstructor = $spy;';
                $helperMethods .= '}';
                $resetCode .= 'self::$_globalTimesCalledConstructor = 0;';
                continue;
            }
            
            $methodName = ucfirst($signatures[2][$key]);
            
            //Methods to track calls
            $helperMethods .= 'public static function _getGlobalTimesCalled' . $methodName . '(){ return self::$_globalTimesCalled' . $methodName . '; }';
            $helperMethods .= 'public static function _resetGlobalTimesCalled' . $methodName . '(){ self::$_globalTimesCalled' . $methodName . ' = 0; }';
            
            //Method for setting mock the values
            $helperMethods .= 'public static function _set' . $methodName . 'Values($values){';
            $helperMethods .= 'if (!is_array($values)) throw new Exception(\'Set values methods expect an array as the input!\');';
            $helperMethods .= 'self::$_values' . $methodName . ' = $values;';
            $helperMethods .= 'self::$_valueNumber' . $methodName . ' = 0;';
            $helperMethods .= '}';
            
            //Method for setting up the spy
            $helperMethods .= 'public static function _set' . $methodName . 'Spy($spy){';
            $helperMethods .= 'if (!is_string($spy)) throw new Exception(\'Spy callback must be string name of function!\');';
            $helperMethods .= 'self::$_spy' . $methodName . ' = $spy;';
            $helperMethods .= '}';
            
            //To reset all counters
            $resetCode .= 'self::$_globalTimesCalled' . $methodName . ' = 0;';
        }
        
        $resetCode = 'public static function _resetAllGlobalCounters() {' . $resetCode . '}';
        
        return $helperMethods . $resetCode;
    }

    
    public static function generateClassMock($file) {
        $contents = self::getCleanFile($file);
        
        //Find the class name
        preg_match('/class ([a-zA-Z][\w\d]*)/i', $contents, $rawClassName);
        $className = $rawClassName[1];
        
        //Find all of the method signatures
        preg_match_all('/public( static)? function ([a-zA-z][\w\d]*)\(.*\)/i', $contents, $signatures);
        
        //Get the class contents
        $members = self::generateMemeberVariables($signatures);
        $mocked  = self::generateMockMethods($signatures);
        $helpers = self::generateHelperMethods($signatures);
        
        //Put the class together
        $mockedClass = "class $className { $members $mocked $helpers }";
        
        //Dynamically add the class
        eval($mockedClass);
    }


    private static function cleanMethodName($methodName) {
        if (strtolower($methodName) === '_construct') {
            return 'Constructor';
        } else {
            return ucfirst($methodName);
        }
    }
    

    public static function resetAllGlobalCounts($className) {
        call_user_func($className . '::_resetAllGlobalCounters');
    }


    public static function getGlobalMethodCount($className, $methodName) {
        return call_user_func($className . '::_getGlobalTimesCalled' . Mocktail::cleanMethodName($methodName));
    }
    
    
    public static function resetGlobalMethodCount($className, $methodName) {
        call_user_func($className . '::_resetGlobalTimesCalled' . Mocktail::cleanMethodName($methodName));
    }
    
    
    public static function setMethodReturnValues($className, $methodName, $value = array()) {
        call_user_func($className . '::_set' . Mocktail::cleanMethodName($methodName) . 'Values', $value);
    }


    public static function setSpy($className, $methodName, $functionName) {
        call_user_func($className . '::_set' . Mocktail::cleanMethodName($methodName) . 'Spy', $functionName);
    }

}


?>
