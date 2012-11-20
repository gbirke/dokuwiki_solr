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


 
class action_plugin_solr extends DokuWiki_Action_Plugin {
  
  const PAGING_SIZE = 100;

  /**
   * Allowed actions that can be dispatched by dispatch_search
   *
   * @var array
   */
  protected $allowed_actions = array('solr_search', 'solr_adv_search');

  /**
   * Current query string used in assemble_params
   * @var string
   */
  protected $currentQueryStr = '';
  
  /**
   * return some info
   */
  function getInfo(){
    return array(
		 'author' => 'Gabriel Birke',
		 'email'  => 'birke@d-scribe.de',
		 'date'   => '2012-05-30',
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
    $helper = $this->loadHelper('solr', true);
    spl_autoload_register(array($helper, 'autoload_classes'));

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
   * Display advanced search form and display search results
   */
  protected function page_solr_adv_search() {
    $helper = $this->loadHelper('solr', true);
    echo $helper->htmlAdvancedSearchform();

    $resultPagesize = $this->getConf('result_pagesize');
    $contentPagingsize = $resultPagesize > 0 ? $resultPagesize : self::PAGING_SIZE;
    $handlers = array('advanced_search' => new Solr_QueryHandler_AdvancedSearch($contentPagingsize, $this->getStartEntry()));
    $handlers = trigger_event('SOLR_QUERY_PARAMS', $handlers, array($this, 'assemble_params'));
    $rendererData = array(
        'queryHandlers' => $handlers,
        'renderers' => array('advanced_search' => new Solr_Renderer_Content(array(
            'num_snippets' => $this->getConf('num_snippets'),
            'nothingfound' => $this->getLang('nothingfound'),
            'prev_label'   => $this->getLang('prev_label'),
            'next_label'   => $this->getLang('next_label'),
            'pagingSize'   => $contentPagingsize,
            'do_paging'    => $resultPagesize > 0,
        )))
    );
    trigger_event('SOLR_RENDER_QUERIES', $rendererData, array($this, 'render_queries'));
  }
  
  /**
   * Do a simple search and display search results
   */
  protected function page_solr_search() {
    global $QUERY;

    $resultPagesize = $this->getConf('result_pagesize');
    $contentPagingsize = $resultPagesize > 0 ? $resultPagesize : self::PAGING_SIZE;
    $queryHandlers = array(
        'title'   => new Solr_QueryHandler_Title(self::PAGING_SIZE),
        'content' => new Solr_QueryHandler_Content($contentPagingsize, $this->getStartEntry($resultPagesize))
    );
    
    if($resultPagesize > 0 && $this->getSearchPage() > 1) {
        unset($queryHandlers['title']);
    }
    $this->currentQueryStr = $QUERY;
    $queryHandlers = trigger_event('SOLR_QUERY_PARAMS', $queryHandlers, array($this, 'assemble_params'));

    $queryRenderers = array(
        'title' => new Solr_Renderer_Title(array(
            'header' => $this->getLang('quickhits'),
            'ul_class' => 'search_quickhits',
            'pagingSize' => self::PAGING_SIZE
        )),
        'content' => new Solr_Renderer_Content(array(
            'num_snippets' => $this->getConf('num_snippets'),
            'nothingfound' => $this->getLang('nothingfound'),
            'num_found'    => $this->getLang('num_found'),
            'all_hits'     => $this->getLang('all_hits'),
            'prev_label'   => $this->getLang('prev_label'),
            'next_label'   => $this->getLang('next_label'),
            'pagingSize'   => $contentPagingsize,
            'do_paging'    => $resultPagesize > 0,
        ))
    );
    if($resultPagesize > 0 && $this->getSearchPage() > 1) {
        unset($queryRenderers['title']);
    }
    $rendererData = array(
        'queryHandlers' => $queryHandlers,
        'renderers' => $queryRenderers
    );
    trigger_event('SOLR_RENDER_QUERIES', $rendererData, array($this, 'render_queries'));
  }

  public function assemble_params($data) {
    foreach($data as $queryHandler) {
      $queryHandler->createParameters($this->currentQueryStr);
    }
    return $data;
  }

  public function render_queries($data) {
    $helper = $this->loadHelper('solr', true);
    foreach($data['queryHandlers'] as $queryType => $handler) {
      // Don't execute queries which have no renderer
      if(empty($data['renderers'][$queryType])) {
        continue;
      }
      $queryParams = $handler->getParameters();
      // Don't execute queries that query nothing
      if(empty($queryParams['q'])) {
        continue;
      }
      $this->render_query($helper, $queryParams, $data['renderers'][$queryType]);
    }
  }

  protected function render_query($solr, $queryParams, Solr_Renderer_RendererInterface $renderer) {
    $query_str = substr($this->array2paramstr($queryParams), 1);
    try {
      $result = unserialize($solr->solr_query('select', $query_str));
      //echo "<pre>";print_r($content_result);echo "</pre>";
    }
    catch(Exception $e) {
      echo $this->getLang('search_failed');
      return;
    }
    $renderer->renderResult($result);
    if($renderer->continueRendering($result)) {
      $queryParams['start'] = $result['response']['start'] + self::PAGING_SIZE;
      $this->render_query($solr, $queryParams, $renderer);
    }
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
   * Return the current page of a paged search result
   * @return int
   */
  protected function getSearchPage() {
      return empty($_GET['search_page']) || ! intval($_GET['search_page'])? 1 : $_GET['search_page'];
  }

  /**
   * Get "start" parameter for Solr search from $_GET[search_page] parameter
   *
   * If paging is off ($resultPagesize = 0), return 0
   *
   * @param int $resultPagesize
   * @return int
   */
  protected function getStartEntry($resultPagesize) {
      return $resultPagesize > 0 && !empty($_GET['search_page']) ? ($_GET['search_page'] - 1) * $resultPagesize : 0;
  }

    /**
   * Handle AJAX request for quickly displaying titles
   */
  public function quicksearch(&$event, $params){
    if($event->data != 'solr_qsearch') {
      return;
    }
    $queryHandlers = array(
        'quicksearch'   => new Solr_QueryHandler_Title(self::PAGING_SIZE),
    );
    $this->currentQueryStr = $_REQUEST['q'];
    $queryHandlers = trigger_event('SOLR_QUERY_PARAMS', $queryHandlers, array($this, 'assemble_params'));

    $rendererData = array(
        'queryHandlers' => $queryHandlers,
        'renderers' => array(
          'quicksearch' => new Solr_Renderer_Title(array(
              'header' => $this->getLang('quickhits'),
              'pagingSize' => self::PAGING_SIZE
          ))
        )
    );
    trigger_event('SOLR_RENDER_QUERIES', $rendererData, array($this, 'render_queries'));
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
