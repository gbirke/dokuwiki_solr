<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

require_once dirname(__FILE__).'/RendererInterface.php';

/**
 * Description of Base
 *
 * @author birke
 */
abstract class Solr_Renderer_Decorator implements Solr_Renderer_RendererInterface {

  /**
   * @var array
   */
  protected $options;

  /**
   *
   * @var Solr_Renderer_RendererInterface
   */
  protected $rendererComponent;

  public function __construct(Solr_Renderer_RendererInterface $rendererComponent, $options = array()) {
    $this->options = array_merge(
            array('pagingSize' => 100),
            $options
    );
    $this->rendererComponent = $rendererComponent;
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
    $count = 0;
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

  public function renderDocument($result, $index, $count = 0) {
    return $this->rendererComponent->renderDocument($result, $index);
  }

  public function renderNothingfound($result) {
    $this->rendererComponent->renderNothingfound($result);
  }

  public function renderPrefix($result) {
    $this->rendererComponent->renderPrefix($result);
  }

  public function renderSuffix($result) {
    $this->rendererComponent->renderSuffix($result);
  }


}
