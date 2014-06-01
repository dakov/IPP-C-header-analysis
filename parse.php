<?php

#CHA:xkovar66

/**
 * Konecny automat pro redukci vstupnich souboru. Redukci je mysleno odstraneni
 * komentaru, maker a nahrazeni vsech retezcu za prazdne.
 */
class Fsm {
    /* definice stavu automatu */
    const s_start = 1;
    
    const s_div = 2;
    const s_line = 3;
    const s_line_esc = 4;
    
    const s_block = 5;
    const s_block_break = 6;
    
    const s_macro = 7;
    const s_macro_break = 8;
    
    const s_str_load = 9;
    const s_str_esc = 10;
    
    function __construct() {
        mb_internal_encoding("UTF-8");
    }
    /**
     * Pripoji znak na konec retezce $dest
     */
    function append($dest, $ch) {
        return $dest .= $ch;
    }
    
    /**
     * Odebere znak z konce retezce $dest. 
     */
    function pop($dest) {
        return rtrim($dest, "/");
    }
        
    /**
     * Rozbije utf-8 retezec na pole multibytovych znaku
     */
    function utf8split($str) {
      $arr = array();
      $len = mb_strlen($str);
      
      for ($i = 0; $i < $len; ++$i) {
        $arr[] = mb_substr($str, $i, 1);
      }
      
      return $arr;
    }
    
    /**
     * Provede redukci vstupniho retezce $content. Pozor, zpracovava retezec
     * jako vicebajtove znaky v kodovani utf-8. Funkce ocekava, ze vstupni
     * retezec je v tomto formatu jiz pripraven.
     * 
     * @param string $content Retezec v kodovani utf-8
     * @return string Retezec obsahujici redukovany vstupni soubor.
     */
    function reduce($content) {
        $len = mb_strlen($content);
        
        $reduced = "";
        $state = self::s_start;
        
        for($i=0; $i < $len; ++$i) {
            //$ch = iconv_substr($content, $i, 1);
            $ch = mb_substr($content, $i, 1);
            
            switch ($state) {
                case self::s_start:
                    if ($ch == "/") {
                        $reduced = $this->append($reduced, $ch);
                        $state = self::s_div;
                    }
                    else if ($ch == "#") {
                        $state = self::s_macro;
                    }
                    else if ($ch == "\"") {
                        $state = self::s_str_load;
                        $reduced = $this->append($reduced, $ch);
                    }
                    else {
                        $reduced = $this->append($reduced, $ch);
                    }
                    break;
                    
                case self::s_div:
                    
                    if ($ch == "/") {
                        $reduced = $this->pop($reduced);
                        $state = self::s_line;
                    } 
                    else if ( $ch == "*" ) {
                        $reduced = $this->pop($reduced);
                        $state = self::s_block;
                    }
                    else {
                        $reduced = $this->append($reduced, $ch);
                        $state = self::s_start;
                    }
                    break;
                    
                case self::s_line:
                    if ($ch == "\\") {
                        $state = self::s_line_esc;
                    } 
                    else if ($ch == "\n"){
                        $this->append($reduced, $ch); //mezeru vloz
                        $state = self::s_start;
                    } else {
                        $state = self::s_line;
                    }
                    break;
                case self::s_line_esc:
                    $state = self::s_line;
                    break;
                
                case self::s_block:
                    if ( $ch == "*" ) {
                        $state = self::s_block_break;
                    }
                    else {
                        $state = self::s_block;
                    }
                    break;
                    
                case self::s_block_break:
                    if ( $ch == "/" ) {
                        $state = self::s_start;
                    } 
                    else if ($ch == "*") {
                        $state = self::s_block_break;
                    }
                    else {
                        $state = self::s_block;
                    }
                    break;
                case self::s_macro:
                    
                    if ($ch == "\\") {
                        $state = self::s_macro_break;
                    } 
                    else if ($ch == "\n") {
                        $state = self::s_start;
                    }
                    else {
                        $state = self::s_macro;
                    }
                    break;
                    
                case self::s_macro_break:
                    $state = self::s_macro;
                    break;
                
                case self::s_str_load:
                    
                    if ($ch == "\\") {
                        $state = self::s_str_esc;
                    }
                    else if($ch == "\"") {
                        $reduced = $this->append($reduced, $ch);
                        $state = self::s_start;
                    }
                    else {
                        $state = self::s_str_load;
                    }
                    break;
                    
                case self::s_str_esc:
                    $state = self::s_str_load;
                    break;
            }
        }
        
        return $reduced;
    }
}

/**
 * Trida provadejici zpracovani vstupniho souboru.
 */
class Parser {
    
    /**
     * Pole jmen jiz zpracovanych funkci
     * @var array
     */
    private $processed;
    
    /**
     * @param ConfigSet $cfg Instance konfigurace programu
     * @param array $files Pole zpracovavanych jmen souboru
     */
    function __construct($cfg, $files) {
        $this->files = $files;
        $this->cfg = $cfg;
        $this->fsm = new Fsm();
    }
    
    /**
     * Zpracuje vsechny soubory polozku po polozce. Kazdy soubor otevre prave 
     * kdyz ma byt zpracovavan.
     */
    public function parseAll(){
        
        $master = new ToplevelTag($this->cfg->wd);
        
        foreach ( $this->files as $filename ) { 
            
            //vyhazuje InputFileException -> posilam ji vys
            $this->parseSingle($master, $filename);
            
        }
        
        return $master;
    } 
    
    /**
     * Otevre a nacte jeden soubor, zpracuje jej a k elementu $master prida 
     * patricne synovske znacky. 
     * Pokud se nepodari soubor otevrit vyhodi vyjimku InputFileException.
     * 
     * @param $master Element, ke kteremu se pridavaji nove elementy
     * @param $filename Jmeno aktualne zpracovavaneho souboru
     */
    public function parseSingle($master, $filename) {
        // otevre soubor
	$this->processed = array();
        $content = file_get_contents($filename);
        
        if ( $content === false ) {
            throw new InputFileException("Unable to read input file: '$filename'\n");
        }
        
        $from= realpath($this->cfg->wd); 
        $to = realpath($filename);
        
        // Pokud je hodnota atributu dir korenove elementu prazdna ( --input 
        // obsahuje cestu k souboru), pak obsahem tohoto atributu je 
        // hodnota parametru --input.
        if ( $this->cfg->wd === "" ) {
            
            $fileAttr = $this->cfg->input;
            
        } else {
            // hodnota atributu dir korenoveho elementu je neprazdna, pak bude cesta 
             // k souboru relativni k teto hodnote
            $fileAttr = $this->relativePath($from, $to);
        }
        
        $str = $this->fsm->reduce($content);
        $this->scan($master, $fileAttr, $str);
    }
    
    /**
     * Prozkouma vstupni soubor
     * @param $master Rodicovsky element
     * @param string $file Jmeno, ktere bude ulozeno v atributu 'file'
     * @param string $str retezec s obsahem zkoumanoh souboru
     * @throws Exception
     */
    function scan($master, $file, $str) {
        $matches = array();
        
        // klice pro pojmenovane sekce v regexu
        $rettype_key = "rettype";
        $fname_key = "fname";
        $param_key = "params";
        
        $ident = "[a-zA-Z_]\w*";
        $func_re = "/\s*(?<$rettype_key>(?:\s*".$ident."[\s\*]+)+)(?P<$fname_key>".$ident.")\s*\((?P<$param_key>[\s\S]*?)\)\s*(\{|;)/u";
        $varargs_re = "/\.\.\./u";
        
        $count = preg_match_all($func_re, $str, $matches);
        
        if ($count === false) {
            throw new Exception("Chyba regularniho vyrazu\n");
        }
        
        
        for ($i = 0; $i < $count; ++$i) {
            $file = trim($file);
            $fname = trim($matches[$fname_key][$i]);
            $rettype = trim($matches[$rettype_key][$i]);
            
            $varargs = ( preg_match($varargs_re, $matches[$param_key][$i]))  ? 'yes' : 'no';

            $tag = new FuncTag( $file, $fname, $varargs, $rettype, $this->cfg->remove_whitespace);
            
            // nez pridas func-tag k master tagu, rozparsuj jeho argumenty
            $added = $this->scanParams($tag, $matches[$param_key][$i]);
            
            if ($added === false) { // pravdepodobne ma prilis parametru
                continue;
            }
            
            // KONTROLA pro --no-inline
            if ($this->isInline($rettype) && $this->cfg->no_inline === true) {
                continue;
            }
            
            // KONTROLA pro --no-duplicates
            if ($this->isProcessed($fname) && $this->cfg->no_duplicates === true){
                continue;
            }
            $this->markAsProcessed($fname);
            
            $master->addSlave($tag);
        }
        
    }
    /** 
     * Vraci false, pokud by master tag nemel byt pridan do celkoveho stromu
     * jinak vraci pocet zpracovanych parametru (pozor, vraci i 0 !)
     */
    function scanParams($master, $str) {
        
        if (trim($str) === 'void' ){
            return 0;
        }
        
        $params = explode(",", $str);
        $res = array();
        
        foreach ($params as $p) {
            $p = trim($p);
            if ( $p === "..." || $p === "") {
                continue;
            }
            array_push($res, $this->attrSplit($p));
            
        }
        $count = count($res);
        
        // nejsou zadne parametry
        if ($count == 0) {
            return 0;
        }
        
        // funkce ma prilis mnoho parametru => nepridavej ji
        if ($this->cfg->max_par !== null && $count > $this->cfg->max_par) {
            return false;
        }
        
        //pridej parametry
        $number = 1;
        foreach ($res as $pair){
            
            if ( !$pair )
                continue;
            
            list($type, $name) = $pair;
            $slave = new ParamTag( $number, $type, $this->cfg->remove_whitespace);
            $number++;
            
            $master->addSlave($slave);
        }
        
        return $count;
        
    }
    
    /**
     * Rozhodne, zda retezec specifikatoru funkce $rettype definuje inline funkci.
     * @return True / False
     */
    function isInline($rettype) {
        
        if (preg_match("/(\s+|^|;|})(inline)\s+/", $rettype)) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Rozdeli retezec obsahujici parametry funkce na jednotlive parametry
     * a jejich jmena
     * @return Pole obsahujici na indexu 0 typ parametru, na polozce 1 jeho jmeno.
     */
    function attrSplit($param){
        $rev = strrev($param);
        $parse_re = "/(?P<name>\w*[a-zA-Z_])(?P<type>.*)/u";
        
        $matches = array();
        preg_match($parse_re, $rev, $matches);
        
        if (empty($matches)) {
            return NULL;
        }
        
        $x = array(trim(strrev($matches['type'])), trim(strrev($matches['name'])));
        
        return $x;
    }
    
    
    /**
     * Vypocita relativni cestu z $from k $to. Ocekava obe hodnoty absolutni cesty
     * Pocita relativni cestu pouze pro soubory zanorene v podadresarich adresare $from
     */
    function relativePath($from, $to) {
        
        $lenFrom = strlen($from);
        
        return substr($to, $lenFrom+1);
        
    }
    
    /**
     * Zjisti, zda bylajiz funkce se jmenem $func zpracovana
     */
    public function isProcessed($func) {
	
        if ( isset($this->processed[$func]) ) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Nastavi funkci se jmenem $func jako zpracovanou.
     */
    public function markAsProcessed($func) {
        $this->processed[$func] = true;
    }
}
