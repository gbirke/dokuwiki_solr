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
require_once dirname(__FILE__).'/ConnectionException.php';
 
class action_plugin_solr extends DokuWiki_Action_Plugin {
  
  const PAGING_SIZE = 100;
  
  /**
   * Quuery params used in all search requests to Solr
   *
   * @var array
   */
  protected $common_params = array(
    'q.op' => 'AND',
    'wt'   => 'phps',
    'debugQuery' => 'false',
    'start' => 0
  );
  
  /**
   * Query params used in search requests to Solr that highlight snippets
   *
   * @var array
   */
  protected $highlight_params = array(
    'hl' => 'true',
    'hl.fl' => 'content',
    'hl.snippets' => 4,
    'hl.simple.pre' => '!!SOLR_HIGH!!',
    'hl.simple.post' => '!!END_SOLR_HIGH!!'
  );
  
  public $highlight2html = array(
    '!!SOLR_HIGH!!' => '<strong class="search_hit">',
    '!!END_SOLR_HIGH!!' => '</strong>'
  );
  
  protected $allowed_actions = array('solr_search', 'solr_adv_search');
  
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
    $controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE',  $this, 'dispatch_search');
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
        print "solr_indexer: Unexpected response:\n$result\n";
      }
      else {
        print "solr_indexer: index was updated\n";
        // update index file
        $helper->update_idxfile($ID);
      }
    }
    catch(ConnectionException $e) {
      print "solr_indexer: Request failed: ".$e->getMessage().NL;
    }

    // release lock
    @rmdir($lock);
    
    // Stop event propagation to avoid script timeout
    $event->preventDefault();
    $event->stopPropagation();
  }
  
  /**
   * Event handler for displaying the search result page
   */
  function dispatch_search(&$event, $param) {
    // only handle our actions
    if(!in_array($event->data, $this->allowed_actions)) {
      return;
    }
    $method = 'page_'.$event->data;
    $this->$method();

    $event->preventDefault();
    $event->stopPropagation();
  }
  
  /**
   * Display advanced search form and handle the sent form fields
   */
  protected function page_solr_adv_search() {
    global $QUERY;
    $helper = $this->loadHelper('solr', true);
    echo $helper->htmlAdvancedSearchform();
    
    // Build search string
    $q = '';
    if(!empty($_REQUEST['search_plus'])) {
      $val = utf8_stripspecials(utf8_strtolower($_REQUEST['search_plus']));
      $q .= $this->search_words($val, '+', '*');
    }
    elseif(!empty($QUERY)) {
      $val = utf8_stripspecials(utf8_strtolower($QUERY));
      $q .= $this->search_words($val, '+', '*');
    }
    if(!empty($_REQUEST['search_exact'])) {
      $q .= ' +"'.$_REQUEST['search_exact'].'"';
    }
    if(!empty($_REQUEST['search_minus'])) {
      $val = utf8_stripspecials(utf8_strtolower($_REQUEST['search_minus']));
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
          //$value = utf8_stripspecials(utf8_strtolower($value));
          if(!$value) {
            continue;
          }
          $q .= $this->search_words($value, ''.$key.':', '*');
        }
    }
    $q = trim($q); // remove first space
    // Don't search with empty params
    if(!$q) {
      return;
    }
    
    $content_params = array_merge($this->common_params, $this->highlight_params, array(
        'q' => $q, 
        'rows' => self::PAGING_SIZE,
       // 'q.op' => 'OR'
    ));
    //print("<p>search string: $q</p>");
    print $this->locale_xhtml('searchpage');
    print '<div class="search_allresults">';
    $this->search_query($content_params);
    print '</div>';
    
  }
  
  protected function search_words($str, $prefix='', $suffix='') {
    $words = preg_split('/\s+/', $str);
    $search_words = '';
    foreach($words as $w) {
      $search_words .= ' ' . $prefix . $w . $suffix;
    }
    return $search_words;
  }

  /**
   * Do a simple search and display search results
   */
  protected function page_solr_search() {
    global $QUERY;
    $val = utf8_strtolower($QUERY);
    $q_title .= $this->search_words($val, 'title:', '*');
    $q_text  .= $this->search_words($val, '', '*');
    
    // Prepare the parameters to be sent to Solr
    $title_params = array_merge($this->common_params, array('q' => $q_title, 'rows' => self::PAGING_SIZE));
    $content_params = array_merge($this->common_params, $this->highlight_params, array(
      'q' => $q_text, 
      'rows' => self::PAGING_SIZE, 
      'x-dw-query-type' => 'content' // Dummy parameter to make this query identifyable in handlers for the SOLR_QUERY event
    ));
    
    // Other plugins can manipulate the parameters
    trigger_event('SOLR_QUERY_TITLE', $title_params);
    trigger_event('SOLR_QUERY_CONTENT', $content_params);
    
    $query_str_title = substr($this->array2paramstr($title_params), 1);
    $helper = $this->loadHelper('solr', true);
    
    // Build HTML result
    print $this->locale_xhtml('searchpage');
    flush();

    //do a search for page titles
    try {
      $title_result = unserialize($helper->solr_query('select', $query_str_title));
    }
    catch(ConnectionException $e) {
      echo $this->getLang('search_failed');
    }
    if(!empty($title_result['response']['docs'])){
      print '<div class="search_quickresult">';
      print '<h3>'.$this->getLang('quickhits').':</h3>';
      $helper->html_render_titles($title_result, 'search_quickhits');
      print '<div class="clearer">&nbsp;</div>';
      print '</div>';  
    }
    flush();
    
    // Output search
    print '<div class="search_allresults">';
    $this->search_query($content_params);
    print '</div>';
  }
  
  /**
   * Query Solr and render search result.
   *
   * If the result contains more documents than the PAGING_SIZE constant, 
   * do another Solr request with increased 'start' parameter.
   *
   * @param array $params Solr Search query params
   */
  protected function search_query($params){
    global $QUERY;
    $helper = $this->loadHelper('solr', true);
    $start = empty($params['start']) ? 0 : $params['start']; 
    $query_str = substr($this->array2paramstr($params), 1);
    // Solr query for content
    try {
      $content_result = unserialize($helper->solr_query('select', $query_str));
      //echo "<pre>";print_r($content_result);echo "</pre>";
    }
    catch(Exception $e) {
      echo $this->getLang('search_failed');
      return;
    }
    $q_arr = preg_split('/\s+/', $QUERY);
    $num_snippets = $this->getConf('num_snippets');
    if(!empty($content_result['response']['docs'])){
        $num = $start+1;
        if(!$start) {
          print '<h3>'.$this->getLang('all_hits').':</h3>';
        }
        foreach($content_result['response']['docs'] as $doc){
            $id = $doc['id'];
            if(auth_quickaclcheck($id) < AUTH_READ) {
              continue;
            }
            $data = array('result' => $content_result, 'id' => $id, 'html' => array());
            $data['html']['head'] = html_wikilink(':'.$id, useHeading('navigation')?null:$id, $q_arr);
            if(!$num_snippets || $num < $num_snippets){
                if(!empty($content_result['highlighting'][$id]['content'])){
                  // Escape <code> and other tags
                  $highlight = htmlspecialchars(implode('... ', $content_result['highlighting'][$id]['content']));
                  // replace highlight placeholders with HTML
                  $highlight = str_replace(
                    array_keys($this->highlight2html), 
                    array_values($this->highlight2html), 
                    $highlight
                  );
                  $data['html']['body'] = '<div class="search_snippet">'.$highlight.'</div>';
                }
            }
            $num++;
            // Enable plugins to add data or render result differently.
            print trigger_event('SOLR_RENDER_RESULT_CONTENT', $data, array($this, '_render_content_search_result'));
            flush();
        }
        if($content_result['response']['numFound'] > $content_result['response']['start'] + self::PAGING_SIZE) {
          $params['start'] = $content_result['response']['start'] + self::PAGING_SIZE;
          $this->search_query($params);
        }
    }
    elseif(!$start) { // if the first search result returned nothing, print nothing found message
        print '<div class="nothing">'.$this->getLang('nothingfound').'</div>';
    }
  }
  
  public function _render_content_search_result($data) {
    return '<div class="search_result">'.implode('', $data['html']).'</div>';
  }
  
  /**
   * Convert an associative array to a parameter string.
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
   * Allow the solr_search action if the global variable $QUERY is not empty
   */
  public function allowsearchpage(&$event, $param) {
    global $QUERY;
    if(!in_array($event->data, $this->allowed_actions)) return;
    if(!$QUERY && $event->data ==  'solr_search') {
      $event->data = 'show';
      return;
    }
    $event->preventDefault();
  }
  
  /**
   * Handle AJAX request for quickly displaying titles
   */
  public function quicksearch(&$event, $params){
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
    catch(ConnectionException $e) {
      echo $this->getLang('search_failed');
    }
  
    if(!empty($title_result['response']['docs'])){
      print '<strong>'.$this->getLang('quickhits').'</strong>';
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
    try {
      $helper->solr_query('update', $query);
    }
    catch(ConnectionException $e) {
      msg($this->getLang('delete_failed'), -1);
      dbglog($e->getMessage(), $this->getLang('delete_failed'));
    }
  }
  
}
