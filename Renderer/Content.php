<?php
/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * Description of Content
 *
 * @author birke
 */
class Solr_Renderer_Content extends Solr_Renderer_Base {

  public $highlight2html = array(
    '!!SOLR_HIGH!!' => '<strong class="search_hit">',
    '!!END_SOLR_HIGH!!' => '</strong>'
  );
  
  protected $q_arr;

  public function __construct($options) {
    global $QUERY;
    parent::__construct($options);
    $this->q_arr = preg_split('/\s+/', $QUERY);
  }


  public function renderDocument($result, $index, $count = 0) {
    $doc = $result['response']['docs'][$index];
    $id  = $doc['id'];
    $data = array(
        'prefix' => '<div class="search_result">',
        'head' => html_wikilink(':'.$id, useHeading('navigation')?null:$id, $this->q_arr)
    );
    if(!$this->options['num_snippets'] === 0 || $count < $this->options['num_snippets']) {
        if(!empty($result['highlighting'][$id]['content'])){
          // Escape <code> and other tags
          $highlight = htmlspecialchars(implode('... ', $result['highlighting'][$id]['content']));
          // replace highlight placeholders with HTML
          $highlight = str_replace(
            array_keys($this->highlight2html),
            array_values($this->highlight2html),
            $highlight
          );
          $data['body'] = '<div class="search_snippet">'.$highlight.'</div>';
        }
    }
    $data['suffix'] = '</div>';
    return $data;
  }

  public function renderPrefix($result) {
    print '<div class="search_allresults">';
  }

  public function renderSuffix($result) {
    print '</div>';
  }

  public function renderNothingfound($result) {
    print '<div class="nothing">'.$this->options['nothingfound'].'</div>';
  }
  
}

?>
