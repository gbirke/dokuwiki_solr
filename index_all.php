<?php
/**
 * This file is meant to be run from the command line and indexes all pages
 *
 * @package    solr
 * @author     Gabriel Birke <birke@d-scribe.de>
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 */ 

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../../').'/');
require_once(DOKU_INC.'inc/init.php');
require_once(DOKU_INC.'inc/common.php');
require_once(DOKU_INC.'inc/search.php');
require_once(DOKU_INC.'inc/pageutils.php');
require_once DOKU_INC.'inc/cliopts.php';
require_once(dirname(__FILE__).'/AddDocument.php');
require_once(dirname(__FILE__).'/Pageinfo.php');

// TODO: Add option for deleting index before adding
// handle options
$short_opts = 'hqp';
$long_opts  = array('help', 'quiet', 'progress');
$OPTS = Doku_Cli_Opts::getOptions(__FILE__,$short_opts,$long_opts);
if ( $OPTS->isError() ) {
    fwrite( STDERR, $OPTS->getMessage() . "\n");
    _usage();
    exit(1);
}

$QUIET = false;
$PROGRESS = false;
foreach ($OPTS->options as $key => $val) {
    switch ($key) {
        case 'h':
        case 'help':
            _usage();
            exit;
        case 'q':
        case 'quiet':
          $QUIET = true;
          break;
        case 'p':
        case 'progress':
         $PROGESS = true;
         break;
    }
}


/**
 * Commit with n milliseconds
 */
define('COMMIT_WITHIN', 10000);

$solr = plugin_load("helper", "solr");

$data = array(
    'global_count' => 0,
    'errors' => array()
);
$opts = array();
$start = microtime(true); 
search($data, $conf['datadir'], 'search_solr_index', $opts, '');

if(!$QUIET) {
    printf("\nImported %d pages in %0.3f seconds\n", $data['global_count'], microtime(true)-$start);
    if(!empty($data['errors'])) {
        echo "\nThe following pages encountered an error while importing:\n";
        foreach($data['errors'] as $err) {
            echo "\n{$err['id']}";
        }
    }
    echo "\n";
}

function search_solr_index(&$data,$base,$file,$type,$lvl,$opts) {
    global $QUIET, $PROGRESS, $solr;
    if($type=='f')
    {
        // Import each file individually to detect errors and minimize unimported docs
        $id = pathID($file);
        $info = new Solr_Pageinfo($id);
        $writer = new XmlWriter();
        $writer->openMemory();
        $doc = new Solr_AddDocument($writer);
        $doc->start(COMMIT_WITHIN);
        $doc->addPage($info->getFields());
        $doc->end();
        $xmldoc = $writer->outputMemory();
        $result = $solr->solr_query('update', '', 'POST', $xmldoc);
        $xml = simplexml_load_string($result);
        // Check response
        if($xml->getName() != "response") {
            $data['errors'][] = array('id' => $id, 'result' => $result);
            if(!$QUIET) {
                /*
                echo $result;
                echo $xmldoc;
                */
            }
        }
        $data['global_count']++;
        // Show progress dots every 100 pages
        if(!($data['global_count'] % 100)) {
            echo ".";
        }
    }
    return true;
}

function _usage() {
  print "Usage: index_all.php <options>
    
  Update Solr index for all pages..
    
    OPTIONS
        -h, --help     show this help and exit
        -q, --quiet    don't produce any output
        -p, --progress show progress
";    
}


