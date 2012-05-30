<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * Emit params for title query
 *
 * @author birke
 */
class Solr_QueryHandler_Title extends Solr_QueryHandler_Base {
  
  protected $pagingSize = 100;
  
  public function __construct($pagingSize) {
    $this->pagingSize = $pagingSize;
  }

  public function createParameters($searchString) {
    $val = utf8_strtolower($searchString);
    $q = $this->search_words($val, '', '*');
    $this->parameters = array_merge($this->common_params, array(
        'q' => $q, 
        'rows' => $this->pagingSize,
        'df' => 'title'
    ));
  }

  
}

?>
