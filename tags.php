<?php

#CHA:xkovar66

/**
 * Abstraktni trida definujici zakladni rozhrani pro praci s jednoduchymi
 * XML znackami a vazby mezi nimi.
 */
abstract class Tag {
    
    protected $level = 0;
    
    function __construct() {
    }
    
    /**
     * Provede redukci bilych znaku v retezci $str.
     * @return Vraci redukovany vstupni retezec
     */
    function reduceWhitespace($str) {
        $output = preg_replace("/\s+/", ' ', $str);
        $output = preg_replace("/\s*\*\s*/", '*', $output);
        
        return $output;
    }
}

/**
 * Definuje rozhrani pro parove znacky, ktere mohou agregovat synovske znacky.
 */
abstract class MasterTag extends Tag{
    
    protected $slaves = array();
    
    /**
     * Priradi znacce synovsky element.
     */
    function addSlave($slave) {
        array_push($this->slaves, $slave);
    }
    
    function slaves() {
        return $this->slaves;
    }
    
    abstract function openTag();
    abstract function closeTag();
}

/**
 * Predstavuje rodicovskou znaku vsech ostatnich znacek.
 */
class ToplevelTag extends MasterTag {
    
    function __construct( $wd ) {
        parent::__construct();
        $this->wd = $wd; // $this->cfg->wd;
    }
        
    function __tostring() {
        $str .= $this->openTag();
        
        foreach( $this->slaves as $slave ){
            $str .= $slave;
        }
        
        $str .= $this->closeTag();
        
        return $str;
    }
    
    function openTag(){
        return "<functions dir=\"$this->wd\">";
    }
    function closeTag(){
        return "</functions>";
    }
    
}
/**
 * Popisuje chovani a vlastnosti znacek, uchovavajicich informace o funkcich.
 */
class FuncTag extends MasterTag {
    
    function __construct($file, $name, $varargs, $rettype, $remove_whitespace) {
        parent::__construct();
        $this->level = 1; // Tag funkce bude vzdy na urovni 1
        $this->file = $file;
        $this->name = $name;
        $this->varargs = $varargs;
        $this->rettype = $rettype;
        $this->remove_whitespace = $remove_whitespace;
    }
    
    function __tostring() {
        $str = "";
        
        $str .= $this->openTag();
        
        foreach ($this->slaves as $slave) {
            $str .= $slave;
        }
        
        $str .= $this->closeTag();
        
        return $str;
    }
    
    function openTag(){
        $str = "";
        $rettype = $this->rettype;
        if ($this->remove_whitespace){
            $rettype = $this->reduceWhitespace($this->rettype);
        }

        //$str .= $indent;
        $str .= "<function file=\"$this->file\" name=\"$this->name\" ";
        $str .= "varargs=\"$this->varargs\" rettype=\"$rettype\">";
        
        return $str;
    }
    
    function closeTag(){
        return "</function>";
    }
}

/**
 * Popisuje chovani a vlastnosti znacek, uchovavajicich informace o parametrech funkce.
 */
class ParamTag extends Tag {
    
    protected $level = 2;
    
    function __construct($number, $type, $remove_whitespace) {
        parent::__construct();
        $this->number = $number;
        $this->type = $type;
        $this->remove_whitespace = $remove_whitespace;
    }
    
    function __toString() {
        $str = "";
        
        //$indent = str_repeat($this->spaces, $this->level);
        $type = $this->type;
        
        if ($this->remove_whitespace){
            $type = $this->reduceWhitespace($this->type);
        }
                
        //$str .= $indent;
        $str .= "<param number=\"$this->number\" type=\"$type\" />";
        
        return $str;
    }
    
    function tag() {
        return strval($this);
    }
    
}

/**
 * Trida, ktera trasformuje hierarchii znacek do pozadovenoho formatu.
 * Konkretne vypise vsechny znacky bez mezer za sebou na jeden radek. 
 */
class SimpleFormatter {
    
    function __construct($master){
        $this->master = $master;
    }
    
    function xmlHeader() {
        return '<?xml version="1.0" encoding="utf-8"?>';
    }
    
    function format() {
        $str = $this->xmlHeader();
        $str .= $this->master->openTag();
        
        foreach ( $this->master->slaves() as $func) {
            $str .= $func->openTag();
            
            foreach ($func->slaves() as $param) {
                $str .= $param->tag();
            }
            
            $str .=  $func->closeTag();
        }
        
        $str .= $this->master->CloseTag() . "\n";
        
        return $str;
    }
}

/**
 * Formatuje hierarchii znacek na radky s patricnym odsazenim.
 */
class PrettyFormatter extends SimpleFormatter{
    
    function __construct($master, $indent){
        parent::__construct($master);
        $this->spaces = str_repeat(' ', $indent); 
    }
    
    function format() {
        $str = $this->xmlHeader() . "\n";
        
        $str .= $this->master->openTag() . "\n";
        
        $fIndent = $this->indent(1);
        $pIndent = $this->indent(2);
        
        foreach ( $this->master->slaves() as $func) {
            $str .= $fIndent . $func->openTag() . "\n";
            
            foreach ($func->slaves() as $param) {
                $str .= $pIndent . $param->tag() . "\n";
            }
            
            $str .= $fIndent . $func->closeTag() . "\n";
        }
        
        $str .= $this->master->CloseTag() . "\n";
        
        return $str;
    }
    
    /**
     * Vygeneruje konstantni odsazeni pro elementy urcite urovne zanoreni
     * @return Retezec mezer, odpovidajicich danemu odsazeni
     */
    function indent($lvl) {
        return str_repeat($this->spaces, $lvl);
    }
    
}

