<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * Base class for emitting Solr search parameters
 *
 * Plugins can manipulate the parameters with the getParameter and setParameter
 * methods.
 *
 * @author birke
 */
abstract class Solr_QueryHandler_Base {
  
  /**
   * The parameters generated for the query
   * 
   * @var array
   */
  protected $parameters = array();

  /**
   * Query params used in all search requests to Solr
   *
   * @var array
   */
  protected $common_params = array(
    'q.op' => 'AND',
    'wt'   => 'phps',
    'debugQuery' => 'false',
    'start' => 0
  );

  protected function search_words($str, $prefix='', $suffix='') {
    $words = preg_split('/\s+/', $str);
    $search_words = '';
    foreach($words as $w) {
      $search_words .= ' ' . $prefix . $w . $suffix;
    }
    return $search_words;
  }

  /**
   * Create the parameters for a Solr query from a search string
   *
   * @return array
   */
  abstract public function createParameters($searchString);

  public function getParameters() {
    return $this->parameters;
  }

  public function getParameter($name) {
    return empty($this->parameters[$name]) ? null : $this->parameters[$name];
  }


  public function setParameter($name, $value) {
    $this->parameters[$name] = $value;
    return $this;
  }



}

?>
