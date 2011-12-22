<?php
/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */
 
if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_INC.'inc/plugin.php');
 
class helper_plugin_solr extends DokuWiki_Plugin {
  
  protected $curl_ch;
  protected $curl_initialized = false;
  
  const INDEXER_VERSION = 1;
  
  public function getMethods(){
    return array(
      array(
        'name'   => 'tpl_searchform',
        'desc'   => 'Prints HTML for search form',
        'params' => array(),
        'return' => array()
      ),
      array(
        'name'   => 'html_render_titles',
        'desc'   => 'Prints HTML list with search result of titles',
        'params' => array(
          'title_result' => 'array',
          'ul_class' => 'string'
         ),
        'return' => array()
      ),
      array(
        'name'   => 'lock_index',
        'desc'   => 'Lock index',
        'params' => array(),
        'return' => array('lockdir' => 'string')
      ),
      array(
        'name'   => 'needs_indexing',
        'desc'   => 'Check if page needs indexing',
        'params' => array('id' => 'string'),
        'return' => array('needs_index' => 'boolean')
      ),
      array(
        'name'   => 'update_idxfile',
        'desc'   => 'Mark page as indexed',
        'params' => array('id' => 'string'),
        'return' => array()
      ),
      array(
        'name'   => 'solr_query',
        'desc'   => 'Send request to Solr server and return result string',
        'params' => array(
          'path' => 'string',
          'query' => 'string',
          'method' => 'string',
          'postfields' => 'string'
        ),
        'return' => array('result' => 'string')
      ),
    );
  }
  
  public function tpl_searchform($ajax=false, $autocomplete=true) {
    global $lang;
    global $ACT;
    global $QUERY;

    print '<form action="'.wl().'" accept-charset="utf-8" class="search" id="dw__search" method="get"><div class="no">';
    print '<input type="hidden" name="do" value="solr_search" />';
    print '<input type="text" ';
    if($ACT == 'solr_search') print 'value="'.htmlspecialchars($QUERY).'" ';
    if(!$autocomplete) print 'autocomplete="off" ';
    print 'id="solr_qsearch__in" accesskey="f" name="id" class="edit" title="[F]" />';
    print '<input type="submit" value="'.$lang['btn_search'].'" class="button" title="'.$lang['btn_search'].'" />';
    if($ajax) print '<div id="solr_qsearch__out" class="ajax_qsearch JSpopup"></div>';
    print '</div></form>';
    return true;  
  }
  
  /**
   * Render found pagenames as list
   *
   * @param array $title_result Solr result array
   * @param string $ul_class Class for UL tag
   */
  public function html_render_titles($title_result, $ul_class="") {
    print '<ul'.($ul_class?' class="'.$ul_class.'"':'').'>';
    $count = 0; 
    foreach($title_result['response']['docs'] as $doc){
      $id = $doc['id'];
      if (isHiddenPage($id) || auth_quickaclcheck($id) < AUTH_READ || !page_exists($id, '', false)) {
        continue;
      }
      print '<li> ';
      if (useHeading('navigation')) {
          $name = $doc['title'];
      }else{
          $ns = getNS($id);
          if($ns){
              $name = shorten(noNS($id), ' ('.$ns.')',30);
          }else{
              $name = $id;
          }
      }
      print html_wikilink(':'.$id,$name);
      print '</li> ';
    }
    print '</ul> ';
  }
  
  
  /**
   * Connect to SOLR server and return result
   *
   * @param string $path Solr action path (select, update, etc)
   * @param string $query URL query string parameters
   * @param string $method GET or POST
   * @param string $postfields POST data, used for CURLOPT_POSTFIELDS
   * @return string Solr response (XML or serialized PHP)
   */
  public function solr_query($path, $query, $method='GET', $postfields='') {
    $url = $this->getConf('url')."/{$path}?{$query}";
    $header = array("Content-type:text/xml; charset=utf-8");
    if(!$this->curl_initialized) {
      $this->curl_ch = curl_init();
      $this->curl_initialized = true;
    }
    curl_setopt($this->curl_ch, CURLOPT_URL, $url);
    curl_setopt($this->curl_ch, CURLOPT_HTTPHEADER, $header);
    curl_setopt($this->curl_ch, CURLOPT_RETURNTRANSFER, 1);
    if($method == 'POST') {
      curl_setopt($this->curl_ch, CURLOPT_POST, 1);
      curl_setopt($this->curl_ch, CURLOPT_POSTFIELDS, $postfields);
    }
    curl_setopt($this->curl_ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
    curl_setopt($this->curl_ch, CURLINFO_HEADER_OUT, 1);
    $data = curl_exec($this->curl_ch);
    
    if (curl_errno($this->curl_ch)) {
      throw new Exception(curl_error($this->curl_ch));
    } 
    
    return $data;
  }
  
  /**
   * Return name of the file if page needs to be indexed,  
   * otherwise false.
   * @param string $id
   * @return string|boolean
   */
  function needs_indexing($id) {
    $idxtag = metaFN($id,'.solr_indexed');
    if(@file_exists($idxtag)){
        if(io_readFile($idxtag) >= self::INDEXER_VERSION ){
            $last = @filemtime($idxtag);
            if($last > @filemtime(wikiFN($id))){
                return false;
            }
        }
    }
    return $idxtag;
  }
  
  /**
   * Mark page as indexed
   */
  function update_idxfile($id) {
    $idxtag = metaFN($id,'.solr_indexed');
    return file_put_contents($idxtag, $this->indexer_version);
  }
  
  /**
   * Lock Solr index with a lock directory
   */
  public function lock_index(){
    global $conf;
    $lock = $conf['lockdir'].'/_solr_indexer.lock';
    while(!@mkdir($lock,$conf['dmode'])){
        usleep(50);
        if(time()-@filemtime($lock) > 60*5){
            // looks like a stale lock - remove it
            @rmdir($lock);
            print "solr_indexer: stale lock removed".NL;
        }else{
            print "solr_indexer: indexer locked".NL;
            return;
        }
    }
    if($conf['dperm']) chmod($lock, $conf['dperm']);
    return $lock;
  }
  
}
