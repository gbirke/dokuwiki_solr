<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * A simple base renderer
 *
 * @author birke
 */
abstract class Solr_Renderer_Base implements Solr_Renderer_RendererInterface {

  /**
   * @var array
   */
  protected $options;

  public function __construct($options) {
    $this->options = array_merge(
            array('pagingSize' => 100),
            $options
    );
  }

  public function renderResult($result) {
    // Don't render anything if nothing is found
    if($result['response']['numFound'] == 0) {
      $this->renderNothingfound($result);
      return;
    }
    if($result['response']['start'] == 0 ) {
      $this->renderPrefix($result);
    }
    $count = $result['response']['start'];
    foreach($result['response']['docs'] as $index => $doc){
      $id = $doc['id'];
      if (isHiddenPage($id) || auth_quickaclcheck($id) < AUTH_READ || !page_exists($id, '', false)) {
        continue;
      }
      echo implode("\n", $this->renderDocument($result, $index, $count));
      flush();
      $count++;
    }
    // Last page
    if($result['response']['start'] + $this->options['pagingSize'] > $result['response']['numFound']) {
      $this->renderSuffix($result);
    }
  }

}
