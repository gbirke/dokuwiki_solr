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

  protected $highlight_search;
  protected $highlight_replace;

  protected $q_arr;

  public function __construct($options) {
    global $QUERY;
    $options = array_merge(array(
        'highlight2html' => array(
            '!!SOLR_HIGH!!' => '<strong class="search_hit">',
            '!!END_SOLR_HIGH!!' => '</strong>'
        )),
        $options
    );
    parent::__construct($options);
    $this->q_arr = preg_split('/\s+/', $QUERY);
    $this->highlight_search = array_keys($options['highlight2html']);
    $this->highlight_replace = array_values($options['highlight2html']);
  }


  public function renderDocument($result, $index, $count = 0) {
    $doc = $result['response']['docs'][$index];
    $id  = $doc['id'];
    $data = array(
        'prefix' => '<div class="search_result">',
        'head' => html_wikilink(':'.$id, useHeading('navigation')?null:$id, $this->q_arr)
    );
    if($this->options['num_snippets'] == 0 || $count < $this->options['num_snippets']) {
        if(!empty($result['highlighting'][$id]['content'])){
          // Escape <code> and other tags
          $highlight = htmlspecialchars(implode('... ', $result['highlighting'][$id]['content']));
          // replace highlight placeholders with HTML
          $highlight = str_replace(
            $this->highlight_search,
            $this->highlight_replace,
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
    if(!empty($this->options['all_hits'])) {
      print "\n<h3>".$this->options['all_hits']."</h3>";
    }
    if(!empty($this->options['num_found'])) {
      print "\n<p class='num_found'>";
      print str_replace(
              array(':numFound', ':QTime'),
              array($result['response']['numFound'], sprintf('%0.3f', $result['responseHeader']['QTime']/1000)),
              $this->options['num_found']
      );
      print "</p>\n";
    }
  }

  public function renderSuffix($result) {
    print '</div>';
  }

  public function renderNothingfound($result) {
    print '<div class="nothing">'.$this->options['nothingfound'].'</div>';
  }
  
}

?>
