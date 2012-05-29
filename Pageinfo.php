<?php
/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * This class gathers field information
 */
class Solr_Pageinfo {

  protected $id;
  protected $writer; 
  
  public function __construct($id){
    $this->id = $id;
  }
  
  public function getFields(){
    $fields = array(
      'id' => $this->id,
      'content' => rawWiki($this->id)
    );
    $meta = p_get_metadata($this->id, '', true);
    $fields['created'] = date('Y-m-d\TH:i:s\Z', $meta['date']['created']);
    $fields['modified'] = date('Y-m-d\TH:i:s\Z', $meta['date']['modified']);
    $fields['creator'] = $meta['creator'];
    $fields['title'] = $meta['title'];
    $fields['abstract'] = $meta['description']['abstract'];
    if(!empty($meta['contributor'])) {
      foreach($meta['contributor'] as $name) {
        if($name) {
          $fields['contributor'][] = $name;
        }
      }
    }
    if(!empty($meta['relation']['references'])) {
      foreach($meta['relation']['references'] as $id => $num_ref) {
        if($id) {
          $fields['references'][] = $id;
        }
      }
    }
    trigger_event('SOLR_INDEX_FIELDS', $fields);
    return $fields;
  }

}
