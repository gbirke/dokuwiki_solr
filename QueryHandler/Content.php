<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * Emit params for content query
 *
 * @author birke
 */
class Solr_QueryHandler_Content extends Solr_QueryHandler_Base {
  
  protected $pagingSize = 100;

  /**
   * Query params used in search requests to Solr that highlight snippets
   *
   * @var array
   */
  public $highlight_params = array(
    'hl' => 'true',
    'hl.fl' => 'content',
    'hl.snippets' => 4,
    'hl.simple.pre' => '!!SOLR_HIGH!!',
    'hl.simple.post' => '!!END_SOLR_HIGH!!'
  );
  
  public function __construct($pagingSize) {
    $this->pagingSize = $pagingSize;
  }

  public function createParameters($searchString) {
    $val = utf8_strtolower($searchString);
    $q = $this->search_words($val, '', '*');
    $this->parameters = array_merge($this->common_params, $this->highlight_params, array(
        'q' => $q, 
        'rows' => $this->pagingSize,
        'sort' => 'score desc, title asc',
    ), $this->parameters);
  }

  public function getPagingSize() {
    return $this->pagingSize;
  }

  public function setPagingSize($pagingSize) {
    $this->pagingSize = $pagingSize;
  }

}

?>
