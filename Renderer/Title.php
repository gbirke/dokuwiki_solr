<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

require_once dirname(__FILE__).'/Base.php';

/**
 * Renders titles (for quicklinks and search_quickresult)
 *
 * @author birke
 */
class Solr_Renderer_Title extends Solr_Renderer_Base {

  public function renderPrefix($result) {
    echo '<div class="search_quickresult">';
    if(!empty($this->options['header'])) {
      echo '<h3>'.$this->options['header'].'</h3>';
    }
    echo '<ul'.(empty($this->options['ul_class']) ? '' : ' class="'.$this->options['ul_class'].'"').'>';
  }

  public function renderSuffix($result) {
    echo "</ul>";
    echo '<div class="clearer">&nbsp;</div>';
    echo "</div>";
  }
  
  public function renderDocument($result, $index, $count = 0) {
      $doc = $result['response']['docs'][$index];
      $id  = $doc['id'];
      $output = '<li> ';
      if (useHeading('navigation')) {
          $name = $doc['title'];
      } else {
          $ns = getNS($id);
          if($ns){
              $name = shorten(noNS($id), ' ('.$ns.')',30);
          }else{
              $name = $id;
          }
      }
      $output .= html_wikilink(':'.$id, $name);
      $output .= '</li> ';
      return array('head' => $output);
  }

  public function renderNothingfound($result) {
    // Print nothing
  }

}
