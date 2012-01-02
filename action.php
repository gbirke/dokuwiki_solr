<?php
/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
 
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');
require_once(dirname(__FILE__).'/AddDocument.php');
require_once(dirname(__FILE__).'/Pageinfo.php');
 
class action_plugin_solr extends DokuWiki_Action_Plugin {
  
  /**
   * Quuery params used in all search requests to Solr
   *
   * @var array
   */
  protected $common_params = array(
    'q.op' => 'AND',
    'rows' => '100',
    'wt'   => 'phps',
    'debugQuery' => 'true'
  );
  
  /**
   * return some info
   */
  function getInfo(){
    return array(
		 'author' => 'Gabriel Birke',
		 'email'  => 'birke@d-scribe.de',
		 'date'   => '2011-12-21',
		 'name'   => 'Solr (Action component)',
		 'desc'   => 'Update the Solr index during the indexing event, show search page.',
		 'url'    => 'http://www.d-scribe.de/',
		 );
  }
 
  /**
   * Register the handlers with the dokuwiki's event controller
   */
  function register(&$controller) {
    $controller->register_hook('INDEXER_TASKS_RUN', 'BEFORE',  $this, 'updateindex');
    $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE',  $this, 'allowsearchpage');
    $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE',  $this, 'searchpage');
    $controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'quicksearch');
    $controller->register_hook('IO_WIKIPAGE_WRITE', 'AFTER', $this, 'delete_index');
  }
  
  /**
   * Update Solr index
   *
   * This event handler is called from the lib/exe/indexer.php file.
   */
  function updateindex(&$event, $param) {
    global $ID;
    $helper = $this->loadHelper('solr', true);
    
    // Look for index file, if not modified, return
    if(!$helper->needs_indexing($ID)){
      print "solr_indexer: index for $ID up to date".NL;
      return;
    }
    
    // get index lock
    $lock = $helper->lock_index();
    
    // gather page info
    $writer = new XmlWriter();
    $writer->openMemory();
    $info = new Solr_Pageinfo($ID);
    $doc = new Solr_AddDocument($writer);
    $doc->start();
    $doc->addPage($info->getFields());
    $doc->end();

    // post to SOLR
    try {
      $result = $helper->solr_query('update', 'commit=true', 'POST', $writer->outputMemory());
      $xml = simplexml_load_string($result);
      // Check response
      if($xml->getName() != "response") {
        print "solr_indexer: Unexpected response:\n$resulr\n";
      }
      else {
        print "solr_indexer: index was updated\n";
        // update index file
        $helper->update_idxfile($ID);
      }
    }
    catch(Exception $e) {
      print "solr_indexer: Request failed: ".$e->getMessage().NL;
    }

    // release lock
    @rmdir($lock);
    
    // Stop event propagation to avoid script timeout
    $event->preventDefault();
    $event->stopPropagation();
  }
  
  function searchpage(&$event, $param) {
    global $lang;
    global $QUERY;
    if($event->data != "solr_search") {
      return;
    }

    $q_arr = preg_split('/\s+/', $QUERY);
    $q_title = '';
    $q_text  = '';
    // construct query string with field name and wildcards
    foreach($q_arr as $val) {
      $val = utf8_stripspecials(utf8_strtolower($val));
      $q_title .= ' title:'.$val.'*';
      $q_text  .= ' text:'.$val.'*';
    }
    // Prepare the parameters to be sent to Solr
    $highlight_params = array(
      'hl' => 'true',
      'hl.fl' => 'content',
      'hl.snippets' => 4,
      'hl.simple.pre' => '<strong class="search_hit">',
      'hl.simple.post' => '</strong>'
    );
    $title_params = array_merge($this->common_params, array('q' => $q_title));
    $content_params = array_merge($this->common_params, $highlight_params, array('q' => $q_text));
    
    // Other plugins can manipulate the parameters
    trigger_event('SOLR_QUERY_TITLE', $title_params);
    trigger_event('SOLR_QUERY_CONTENT', $content_params);
    
    $query_str_title = substr($this->array2paramstr($title_params), 1);
    $query_str_content = substr($this->array2paramstr($content_params), 1);

    $helper = $this->loadHelper('solr', true);
    
    // Build HTML result
    print p_locale_xhtml('searchpage');
    flush();

    //do quick pagesearch
    // Solr query for title
    try {
      $title_result = unserialize($helper->solr_query('select', $query_str_title));
      //echo "<pre>";print_r($title_result);echo "</pre>";
    }
    catch(Exception $e) {
      echo $this->getLang('search_failed');
    }
  
    if(!empty($title_result['response']['docs'])){
      print '<div class="search_quickresult">';
      print '<h3>'.$lang['quickhits'].':</h3>';
      $helper->html_render_titles($title_result);
      print '<div class="clearer">&nbsp;</div>';
      print '</div>';  
    }
    flush();
    
    // Solr query for content
    try {
      $content_result = unserialize($helper->solr_query('select', $query_str_content));
      //echo "<pre>";print_r($content_result['highlighting']);echo "</pre>";
    }
    catch(Exception $e) {
      echo $this->getLang('search_failed'); 
    }
    $num_snippets = $this->getConf('num_snippets');
    if(!empty($content_result['response']['docs'])){
        $num = 1;
        foreach($content_result['response']['docs'] as $doc){
            $id = $doc['id'];
            $data = array('result' => $content_result, 'id' => $id, 'html' => array());
            $data['html']['head'] = html_wikilink(':'.$id, useHeading('navigation')?null:$id, $q_arr);
            if(!$num_snippets || $num < $num_snippets){
                if(!empty($content_result['highlighting'][$id]['content'])){
                  $data['html']['body'] = '<div class="search_snippet">'.implode('... ', $content_result['highlighting'][$id]['content']).'</div>';
                }
            }
            $num++;
            
            print trigger_event('SOLR_RENDER_RESULT_CONTENT', $data, array($this, '_render_content_search_result'));
            flush();
        }
    }else{
        print '<div class="nothing">'.$lang['nothingfound'].'</div>';
    }
    
    $event->preventDefault();
    $event->stopPropagation();
  }
  
  public function _render_content_search_result($data) {
    return '<div class="search_result">'.implode('', $data['html']).'</div>';
  }
  
  /**
   * Convert an associatoev array to a parameter string.
   * Array values are urlencoded
   *
   * @param array $params
   * @return string
   */
  protected function array2paramstr($params) {
    $paramstr = '';
    foreach($params as $p => $v) {
      $paramstr .= '&'.$p.'='.rawurlencode($v);
    }
    return $paramstr;
  }
  
  /**
   * Allow the solr_serach action if the global variable $QUERY is not empty
   */
  public function allowsearchpage(&$event, $param) {
    global $QUERY;
    if($event->data != 'solr_search') return;
    if(!$QUERY) {
      $event->data = 'show';
      return;
    }
    $event->preventDefault();
  }
  
  /**
   * Handle AJAX request for quickly displaying titles
   */
  public function quicksearch(&$event, $params){
    global $lang;
    if($event->data != 'solr_qsearch') {
      return;
    }
    $q_arr = preg_split('/\s+/', $_REQUEST['q']);
    $q_title = '';
    // construct query string with field name and wildcards
    foreach($q_arr as $val) {
      $val = utf8_stripspecials(utf8_strtolower($val));
      $q_title .= ' title:'.$val.'*';
    }
    $title_params = array_merge($this->common_params, array('q' => $q_title));
    // Other plugins can manipulate the parameters
    trigger_event('SOLR_QUERY_TITLE', $title_params);
    
    $query_str_title = substr($this->array2paramstr($title_params), 1);
    
    $helper = $this->loadHelper('solr', true);

    //do quick pagesearch
    // Solr query for title
    try {
      $title_result = unserialize($helper->solr_query('select', $query_str_title));
      //echo "<pre>";print_r($title_result);echo "</pre>";
    }
    catch(Exception $e) {
      echo $this->getLang('search_failed');
    }
  
    if(!empty($title_result['response']['docs'])){
      print '<strong>'.$lang['quickhits'].'</strong>';
      $helper->html_render_titles($title_result);
    }
    flush();
    $event->preventDefault();
    $event->stopPropagation();
  }

  /**
   * This event handler deletes a page from the Solr index when it is deleted
   * in the wiki.
   */
  public function delete_index(&$event, $params){
    // If a revision is stored, do nothing
    if(!empty($event->data[3])) {
      return;
    }
    // If non-empty content is saved, do nothing
    if(!empty($event->data[0][1])) {
      return;
    }
    // create page ID from event data
    $id = $event->data[1] ? "{$event->data[1]}:{$event->data[2]}" : $event->data[2];
    $helper = $this->loadHelper('solr', true);

    // send delete command to Solr
    $query = $this->array2paramstr(array(
      'stream.body' => "<delete><id>{$id}</id></delete>",
      'commit' => "true"
    ));
    $helper->solr_query('update', $query);
  }
  
}
