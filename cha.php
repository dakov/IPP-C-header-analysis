<?php

#CHA:xkovar66

require_once './tags.php';
require_once './parse.php';

define("ARGV_ERR", 1);
define("INPUT_FILE_ERR", 2);
define("OUTPUT_FILE_ERR", 3);
define("INPUT_FORMAT_ERR", 4);
define("GENERAL_ERR", NULL);

class IllegalArgsException extends Exception {}
class InputFileException extends Exception {}
class OutputFileException extends Exception {}

/**
 * Tiskne napovednu programu na standardni vystup
 */
function help() {
    echo "C header analysis usage:\n";
    echo "--help                Vytiskne napovedu\n";
    echo "--input=fileOrDir     Vstupni soubor / adresar\n"; 
    echo "--output=filename     Vystupni soubor \n";
    echo "--pretty-xml=k        Definuje odsazeni elementu\n"; 
    echo "--no-inline           Program preskoci inline funkce\n"; 
    echo "--max-par=n           Zpracovany jsou pouze funkce n a mene parametry \n"; 
    echo "--no-duplicates       Je zpracovan pouze prvni vyskyt funkce\n"; 
    echo "--remove-whitespace   Odstrani prebytecne mezery v nekterych atributech\n"; 
}

/**
 * Tiskne chybovou zpravu na standardni vystup
 * @param string $msg 
 */
function error($msg) {
    file_put_contents('php://stderr', $msg, FILE_APPEND);
}

/**
 * Zjisti, zda retezec je prefixem jineho retezce.
 * @param string $haystack Zkoumany retezec
 * @param string $needle Hledany vzorek
 * @return boolean
 */
function startswith($haystack, $needle) {
    
    $len = strlen($needle);
    
    if (substr($haystack, 0, $len) == $needle) {
        return true;
    }
    
    return false;    
}

/**
 * Uchovava aktualni instanci nastaveni aplikace
 */
class ConfigSet {        
    
    function __construct($args) {
        $this->no_duplicates = $args['no-duplicates'];
        $this->remove_whitespace = $args['remove-whitespace'];
        $this->indent = $args['pretty-xml'];
        $this->no_inline = $args['no-inline'];
        $this->max_par = $args['max-par'];
        $this->input = $args['input'];
        $this->output = $args['output'];
        
        $this->wd = $this->getWorkingDirectory();
    }
    
    /**
     * Vraci hodnotu, ktera se dle zadani ma objevit v atributu 'dir' hlavniho 
     * elementu.
     * @return Retezec obshaujici pozadovany tvar 
     */
    private function getWorkingDirectory() {
        if ( $this->input == 'stdin' ) {
            return './';
        }
        // je slozka
        if ( is_dir($this->input) ) {
            return $this->input;
        }
        // je soubor
        return '';
    }
    
    function __toString() {
        $str = "";
        $str .= "--no-duplicates      ".intval($this->no_duplicates)      ."\n";
        $str .= "--remove-whitespace  ".intval($this->remove_whitespace)  ."\n";
        $str .= "--pretty-xml         ".$this->indent                     ."\n";
        $str .= "--no-inline          ".intval($this->no_inline)          ."\n";
        $str .= "--max-par            ".intval($this->max_par)            ."\n";
        $str .= "--input              ".$this->input                      ."\n";
        $str .= "--output             ".$this->output                     ."\n";
        
        return $str;
    }
    
}

/**
 * Vraci pole jmen, ktere budou analyzovany. Pokud je $input konkretni soubor
 * obsahuje vystupni pole pouze jejich jmeno. Pokud je adresar,
 * obsahuje pole vsechny vyhovujici soubory (tedy hlavickove soubory).
 * 
 * Pokud je zadan konkretni soubor, ktery neexistuje, je vyhozeny vyjimka
 * InputFileException. Pokud uzivatel nema dostatecna opravneni, vyhazuje 
 * vyjimku UnexpectedValueException.
 * 
 * @param string $input retezec, ktery ma byt expandovan na jmena souboru
 * @return Pole jmen pracovnich souboru
 */
function getFilenames($input) {
    
    $res = array();
  
    if (is_file($input)) {
        array_push( $res, $input );
        return $res;
        
    } else if (is_dir($input)) {

        $path = realpath($input);

        if (!$path) {
            throw new InputFileException("Unable to reach path '$input'");
        }

        // rekurzivne iteruj pres vsechny soubory adresarove struktury
        foreach ( new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($input)
                )
                    as $filename ) {
                // echo $filename . "\n";
                $suffix = pathinfo($filename, PATHINFO_EXTENSION);
                
                if ($suffix == "h"){ // akceptuj pouze hlavickove
                    array_push($res, strval($filename));
                }
                
        }
        
        return $res;

    } else {
        throw new InputFileException("Unable to reach path '$input'");
    }
}

/**
 * Zpracovava programove argumenty do ocekavaneho formatu, 
 * v pripade nelegalni kombinace vraci vyjimku IllegalArgsException
 */
function argparse($argv) {
    
    function assertIsset($parsed, $name) {
        if ( isset($parsed[$name])){
            throw new IllegalArgsException("Argument redefinition: '$name'\n");
        }
    }
    
    $implicit = array(
        'help' => false,
        'no-inline' => false,
        'no-duplicates' => false,
        'remove-whitespace' => false,
        'pretty-xml' => false,
        'max-par' => null, # neaktivni nabidka
        'input' => './',
        'output' => false,
    );
    
    $parsed = array();
    
    foreach ( array_slice($argv,1) as $val ){
        
        /* Boolean argumenty */
        
        if ( $val == '--help' ) {
            assertIsset($parsed, 'help');
            $parsed['help'] = true;
        } 
        else if ($val == '--no-inline') {
            assertIsset($parsed, 'no-inline');
            $parsed['no-inline'] = true;
        }
        else if ( $val == '--no-duplicates' ) {
            assertIsset($parsed, 'no-duplicates');
            $parsed['no-duplicates'] = true;
        }
        else if ( $val == '--remove-whitespace' ) {
            assertIsset($parsed, 'remove-whitespace');
            $parsed['remove-whitespace'] = true;
        }
        
        /* ciselne argumenty s implicitni hodnotou */
        else if(startswith($val, '--pretty-xml')){
            assertIsset($parsed, 'pretty-xml');
            $parsed['pretty-xml'] = 4; // implicitni hodnota
            
            $split = preg_split("/=/", $val);
            $len = count($split);
            
            if ($len == 1){ // je ve tvaru pravdivostniho argumentu
                continue;
            }
            else if ($len == 2 && $split[1] === '' ) { // ma rovnase ale ne hodnotu
                throw new IllegalArgsException("Invalid argument format '--pretty-xml='\n");
            } 

            // je ve validnim tvaru - jeste zkontroluj, jestli je cislo
            if ( ! preg_match("/^[0-9]+$/", $split[1]) ) {
                throw new IllegalArgsException("Value not a number: " . $split[1] . "\n");
            }
            
            $parsed['pretty-xml'] = intval($split[1]);
        }
        /* ciselne argumenty bez implicitni hodnoty */
        else if (startswith($val, '--max-par=')) {
            assertIsset($parsed, 'max-par');
            $argval = substr($val, 10);
            
            // je ve validnim tvaru - jeste zkontroluj, jestli je cislo
            if ( ! preg_match("/^[0-9]+$/", $argval) ) {
                throw new IllegalArgsException("Value not a number: " . $argval ."\n" );
            }
            //je cislo
            $parsed['max-par'] = intval($argval);  
        }
        /* argument pro input */
        else if (startswith($val, "--input=")) {
            assertIsset($parsed, 'input');
            $argval = substr($val, 8);
            $parsed['input'] = $argval;
            
            if (strlen($argval) == 0) {
                throw new IllegalArgsException("Missing value for 'input='\n");
            }
        }
        /* argument pro output */
        else if (startswith($val, "--output=")) {
            assertIsset($parsed, 'output');
            $argval = substr($val, 9);
            $parsed['output'] = $argval;
            
            if (strlen($argval) == 0) {
                throw new IllegalArgsException("Missing value for 'input='\n");
            }
        }
        /* Neplatny argument */
        else{
            throw new IllegalArgsException("Unknown argument or its format: $val\n");
        }
    
    }
    
    // --help nesmi existovat spolecne s jinymi argumenty
    if (isset($parsed['help']) && $parsed['help'] && count($parsed) > 1) {
        throw new IllegalArgsException("Illegal combination of arguments!\n");
    }
    
    // prekryti implicitnich hodnot explicitnimi
    return $parsed + $implicit;

}

/* ===================================
 * Telo skriptu
 * ====================================
 */

// vyuziva se predpokladu, ze semantika kazdeho argumentu je zastoupena i v pripade
// ze neni argument explicitne zadan -> v tomto pripade existuje pro kazdy argument
// implicitni hodnota Po tomto kroku vsechny argumenty nabyvaji pozadovane hodnoty
try {
    $args = argparse($argv);
} catch (IllegalArgsException $ex) {
    error("Error: " . $ex->getMessage());
    exit(ARGV_ERR);
}

// --help musi existovat osamocene, otestuj jeste pred inicializaci kongigurace
if ($args['help']){
    help();
    exit(0);
}

/*
 * PREDPOKLAD: v tento okamzik mam jiz vsechny argumenty inicializovany na 
 * implicitni / explicitni hodnotu!
 */
$cfg = new ConfigSet($args);

// Najdi vsechny soubory, nad kterymi pracujes

try {
    //getFilenames("/home/david/workspace/ifj13");
    $files = getFilenames($cfg->input);
} catch (InputFileException $ex ) {
    error("ERROR: " . $ex->getMessage() . "\n");
    exit(INPUT_FILE_ERR);
} catch (UnexpectedValueException $ex ) {
    error("ERROR: " . $ex->getMessage(). "\n");
    exit(INPUT_FILE_ERR);
}

$parser = new Parser($cfg, $files);

try {
    $master = $parser->parseAll();
} catch (InputFileException $ex) {
    error("ERROR: " . $ex->getMessage() . "\n");
    exit(INPUT_FILE_ERR);
}

function getOutputHandle($filename) {

    if ($filename === false) {
        return STDOUT;
    }
    
    $fh = fopen($filename, "w");
    if ($fh === false) {
        throw new OutputFileException("Unable to open '$filename' for writing.\n");
    }
    
    return $fh;
    
}

try {
    $fout = getOutputHandle($cfg->output);
} catch ( OutputFileException $ex ) {
    error("Error: ". $ex->getMessage() . "\n");
    exit(OUTPUT_FILE_ERR);
}


if ( $cfg->indent === false){
    $formatter = new SimpleFormatter($master);
} else {
    $formatter = new PrettyFormatter($master, $cfg->indent);
}

//zapis vystupu
fwrite($fout, $formatter->format());

fclose($fout);
