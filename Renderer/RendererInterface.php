<?php

/**
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */

/**
 * The interface for all renderers
 */
interface Solr_Renderer_RendererInterface {

  /**
   *  Render a Solr result
   *
   * Usually calls renderPrefix, calls renderDocument in a loop, then calls
   * renderSuffix.
   *
   * @param array $result Result array from Solr query
   */
  public function renderResult($result);
  
  /**
   * Render an individual document from a Solr result.
   * 
   * Individual documents are not rendered as strings but as array - this way, 
   * the individual parts of the result document can be changed and/or 
   * rearranged by decorators.
   *
   * @param array $result Result array from Solr query
   * @param int $index Index of the document to render (in $result['response']['docs'])
   * @param int $count Number of documents that have been rendered (may differ from index because of failed visibility checks)
   * @return array
   */
  public function renderDocument($result, $index, $count=0);

  /**
   * Render the HTML that comes before the result documents
   *
   * @param array $result Result array from Solr query
   */
  public function renderPrefix($result);

  /**
   * Render the  HTML that comes after the result documents
   *
   * @param array $result Result array from Solr query
   */
  public function renderSuffix($result);

  /**
   * Render the  HTML when nothing is found
   *
   * @param array $result Result array from Solr query
   */
  public function renderNothingfound($result);


}
