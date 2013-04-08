<?php
/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * This class takes creates an XML document for adding wiki pages (documents) 
 * to Solr.
 */ 
class Solr_AddDocument {
  
  protected $writer; 
  
  public function __construct(XMLWriter $writer){
    $this->writer = $writer;
  }
  
  public function start($commitWithin=0){
    $this->writer->startDocument();
    $this->writer->startElement('add');
    if($commitWithin > 0) {
      $this->writer->writeAttribute('commitWithin', $commitWithin);
    }
  }
  
  public function end(){
    $this->writer->endElement();
  }
  
  /**
   * Add a wiki page (document) 
   * 
   * @param array $fields Fieldname => value(s) pairs to add
   */
  public function addPage($fields){
    $writer = $this->writer;
    $writer->startElement("doc");
    foreach($fields as $name => $value) {
      if(is_array($value)) {
        foreach($value as $v) {
          $this->_outputField($name, $v);
        }
      }
      else {
        $this->_outputField($name, $value);
      }
    }
    $writer->endElement();
  }
  
  public function getWriter(){
    return $this->writer;
  }
  
  protected function _outputField($name, $content){
    $cleanContent = preg_replace('@[\x00-\x08\x0B\x0C\x0E-\x1F]@', ' ', $content);
    $this->writer->startElement("field");
    $this->writer->writeAttribute('name', $name);
    $this->writer->text($cleanContent);
    $this->writer->endElement();
  }
  
  
}
