<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <gb@birke-software.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * Ignores searchString and generates the "q" parameters from $_REQUEST
 *
 * @author birke
 */
class Solr_QueryHandler_AdvancedSearch extends Solr_QueryHandler_Content {
  
  public function createParameters($searchString) {
    global $QUERY;

    // Build search string
    $q = '';
    if(!empty($_REQUEST['search_plus'])) {
      $val = utf8_strtolower($_REQUEST['search_plus']);
      $q .= $this->search_words($val, '+', '*');
    }
    elseif(!empty($QUERY)) {
      $val = utf8_strtolower($QUERY);
      $q .= $this->search_words($val, '+', '*');
    }
    if(!empty($_REQUEST['search_exact'])) {
      $q .= ' +"'.$_REQUEST['search_exact'].'"';
    }
    if(!empty($_REQUEST['search_minus'])) {
      $val = utf8_strtolower($_REQUEST['search_minus']);
      $q .= $this->search_words($val, '-', '*');
    }
    if(!empty($_REQUEST['search_ns'])) {
      foreach($_REQUEST['search_ns'] as $ns) {
        if(($ns = trim($ns)) != '') {
          $q .= ' idpath:'.strtr($ns, ':','/');
        }
      }
    }
    if(!empty($_REQUEST['search_fields'])) {
        foreach($_REQUEST['search_fields'] as $key => $value) {
          if(!$value) {
            continue;
          }
          $value = utf8_strtolower($value);
          $q .= $this->search_words($value, $key.':', '*');
        }
    }
    $q = trim($q); // remove first space

    $this->parameters = array_merge($this->common_params, $this->highlight_params, array(
        'q' => $q
    ), $this->parameters);
  }

  
}

?>
