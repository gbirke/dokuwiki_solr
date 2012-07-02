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
require_once dirname(__FILE__).'/ConnectionException.php';
 
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
    if($ACT == 'solr_search' || ($ACT == 'solr_adv_search' && !empty($QUERY))) print 'value="'.htmlspecialchars($QUERY).'" ';
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
    
    $event_data = array(
      'path' => $path,
      'query' => $query,
      'method' => $method,
      'postfields' => $postfields,
      'curl' => $this->curl_ch,
      'result' => null
    );
    $evt = new Doku_Event('SOLR_QUERY', $event_data);
    if($evt->advise_before(true)) {
      $evt->data['result'] = curl_exec($this->curl_ch);
      if (curl_errno($this->curl_ch)) {
        throw new ConnectionException(curl_error($this->curl_ch));
      } 
    }
    $evt->advise_after();
    return $evt->data['result'];
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
    return file_put_contents($idxtag, self::INDEXER_VERSION);
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
  
  function htmlAdvancedSearchBtn() {
    global $ACT;
    global $ID;
    $id = $ACT == 'solr_search' ? $ID : '';
    return html_btn('solr_adv_search', $id, '', array('do' => 'solr_adv_search'), 
      'get', $this->getLang('show_advsearch'), $this->getLang('btn_advsearch'));  
  }
  
  
  /**
   * Output advanced search form. 
   *
   */
  function htmlAdvancedSearchform()
  {
	  global $QUERY;
	  $search_plus = empty($_REQUEST['search_plus']) ? $QUERY : $_REQUEST['search_plus'];
	  ptln('<form action="'.DOKU_SCRIPT.'" accept-charset="utf-8" class="search" id="dw__solr_advsearch" name="dw__solr_advsearch"><div class="no">');
		ptln('<input type="hidden" name="do" value="solr_adv_search" />');
		ptln('<input type="hidden" name="id" value="'.$QUERY.'" />');
		ptln('<table class="searchfields">');
		ptln('	<tr>');
		ptln('		<td class="advsearch-label1"><strong>'.$this->getLang('findresults').'</strong></td>');
	  ptln('	</tr>');
		ptln('	<tr>');
		ptln('		<td class="label"><label for="search_plus">'.$this->getLang('allwords').'</label></td>');
		ptln('		<td>	<input type="text" id="search_plus" name="search_plus" value="'.htmlspecialchars($search_plus).'" /> </td>');
		ptln('	</tr>');
		ptln('	<tr>');
		ptln('		<td class="label"><label for="search_exact">'.$this->getLang('exactphrase').'</label></td>');
		ptln('		<td>	<input type="text" id="search_exact" name="search_exact" value="'.htmlspecialchars($_REQUEST['search_exact']).'" /> </td>');
		ptln('	</tr>');
		ptln('	<tr>');
		ptln('		<td class="label"><label for="search_minus">'.$this->getLang('withoutwords').'</label></td>');
		ptln('		<td>	<input type="text" id="search_minus" name="search_minus" value="'.htmlspecialchars($_REQUEST['search_minus']).'" /> </td>');
		ptln('	</tr>');
		ptln('	<tr>');
		ptln('		<td class="advsearch-label2">'.$this->getLang('in_namespace').'</td>');
		ptln('		<td id="advsearch-nsselect">');
    ptln($this->htmlNamespaceSelect(array(
      'name' => 'search_ns[]',
      'multiple' => true,
      'selected' => empty($_REQUEST['search_ns'])?array():$_REQUEST['search_ns'],
      'class' => 'search-ns',
      'checkacl' => true
    )));  
		ptln('		</td>');
		ptln('	</tr>');
		// TODO: Radio buttons for Wildcard yes/no
		ptln('</table>');
		// More search fields
		ptln('<div id="disctinct_searchfields">');
		$fields = array(
		  'title' => array(
		    'label' => $this->getLang('searchfield_title'),
		    'field' => $this->htmlAdvSearchfield('title')
		  ),
		  'abstract' => array(
		    'label' => $this->getLang('searchfield_abstract'),
		    'field' => $this->htmlAdvSearchfield('abstract')
		  ),
		  'creator' => array(
		    'label' => $this->getLang('searchfield_creator'),
		    'field' => $this->htmlAdvSearchfield('creator')
		  ),
		  'contributor' => array(
		    'label' => $this->getLang('searchfield_contributor'),
		    'field' => $this->htmlAdvSearchfield('contributor')
		  ),
		);
		trigger_event('SOLR_ADV_SEARCH_FIELDS', $fields);
		ptln('  <table class="searchfields additional">');
		foreach($fields as $field_id => $field) {
		  ptln('    <tr><td class="label">');
		  ptln('      <label for="search_field_'.$field_id.'">'.$field['label'].'</label></td><td>'.$field['field']);
		  ptln('    <td></tr>');
		}
		ptln('  </table>');
		ptln('</div>');
		ptln('			<input type="submit" value="'.$this->getLang('btn_search').'" class="button" title="'.$this->getLang('btn_search').'" />');
		ptln('	<br style="clear:both;" /></div>');
		ptln('</form>');
  }
  
  function htmlNamespaceSelect($options)
  {
    global $conf;
    $options = array_merge(array(
      'selected' => array(),
      'multiple' => false,
      'name' => 'namespaces',
      'class' => '',
      'id' => '',
      'size' => 8,
      'depth_prefix' => 'nsDepth',
      'depth_indent' => 5,
      'depth_char' => '&nbsp;'
      ), $options);
    
    // Namespace selection
		$s = sprintf('<select name="%s" size="%d" %s%s%s >', 
      $options['name'],
      $options['size'],
      ($options['multiple'] ? ' multiple="multiple"' : ''),
      ($options['id'] ? " id=\"{$options['id']}\"" : ''),
      ($options['class'] ? " class=\"{$options['class']}\"" : '')
    );
    $s .= '<option value=""'.(empty($options['selected']) || in_array('', $options['selected'])?' selected="selected"':'').'>'.$this->getLang('ns_all').'</option>';
    $namespaces = array();
		$opts=array();
		require_once(DOKU_INC.'inc/search.php');
		search($namespaces, $conf['datadir'],'search_namespaces', $opts);
		sort($namespaces);
		foreach($namespaces as $row) {
      
			$depth = substr_count($row['id'], ':');
			$s .= sprintf('  <option value="%s"%s%s>%s</option>',
        $row['id'],
        $options['depth_prefix'] ? ' class="'.$options['depth_prefix'].$depth.'"' : '',
        in_array($row['id'], $options['selected']) ? ' selected="selected"' : '',
				str_repeat($options['depth_char'], $depth * $options['depth_indent']).preg_replace('/[a-z0-9_]+:/', '', $row['id'])
      );
		}
		$s .= '</select>';
    return $s;
  }
  
  public function htmlAdvSearchfield($name){
    $s = '<input type="text" name="search_fields['.$name.']" id="search_field_'.$name.'" ';
    if(!empty($_REQUEST['search_fields'][$name])) {
      $s .= ' value="'.htmlspecialchars($_REQUEST['search_fields'][$name]).'"';
    }
    $s .= '/>';
    return $s;
  }
  
}
