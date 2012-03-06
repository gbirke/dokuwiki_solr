Solr Search Plugin
==================

This DokuWiki plugin enables you to index and search your wiki pages in a Solr  server installation.

Installation
------------
Installation has three stages - basic installation, Solr configuration and integration with your DokuWiki template.

For **basic installation** put the folder containing this README in the `lib/plugins` folder of your DokuWiki installation.  
**IMPORTANT:** If you downloaded the plugin from GitHub, make sure that that folder is named `solr`! Otherwise the plugin won't be found.

For **Solr configuration** install and configure a Solr server with the instructions from the Solr Wiki (http://wiki.apache.org/solr/FrontPage ). For testing purposes you can use the example environment in the `example` folder of the Solr distribution. On the command line, change to the example folder and type

    java -jar start.jar

The `schema.xml` file that comes with this plugin can be used as your starting  point for creating a Solr search schema. Consider adding stop words to the stop word list. Also consider using language-specific stemmers and domain-specific synonyms.

If your Solr server is not located at the URL http://localhost:8983/solr you have to open the DokuWiki configuration page and set the Solr URL in the input field for the Solr plugin.

The last step of the installation is the **integration with your DokuWiki template**, i.e. replacing the standard wiki search field with the Solr search field. Open the `main.php` file of you template and look for the code

     <?php tpl_searchform(); ?>

Replace it with the following code:

    <?php 
      $solr =& plugin_load("helper", "solr");
      $solr->tpl_searchform(true, false); // Search field with ajax and no autocomplete
    ?> 
    
This will create a search form where the search terms are searched in the content of the document. Search terms that occur in the first headline (the page title) or the first paragraph (the page abstract) will give the result document a higher ranking. Wildcards are automatically added at the end of each search term.

### Advanced Search ###
The plugin provides a form for advanced search where users can search for exact phrases, exclude words, search inside page titles, abstracts and namespaces and search for specific authors. To create a button to the advanced search form, place the following code at the appropriate place in your `main.php`:

    <?php
      $solr =& plugin_load("helper", "solr");
      $solr->htmlAdvancedSearchBtn();
     ?>

Indexing your wiki
------------------

### Indexing all pages ###
You can call the command line script `index_all.php` that comes with this plugin to index all of your wiki pages. The speed of this script greatly depends on your server speed, so you should not have an execution time limiit for PHP scripts started on the command line.

### Indexing individual pages ###
Each page is also indexed when it is visited by a user. See the next section on how the indexing mechanism works.

### The indexing mechanism ###
After installing the plugin it will index every page using the DokuWiki indexing mechanism: An invisible graphic that calls the file `lib/exe/indexer.php`. `indexer.php` issues an event which is handled by the Solr plugin if the page was modified since it was last indexed. After the plugin has indexed a page, it creates a file with the suffix `.solr_indexed` in the page's meta directory. If the modification date of this file is greater than the page modification date, the plugin does nothing and the other indexing actions specified in `indexer.php` are taken.

Planned Improvements
--------------------
Searching by date: At the moment the creation and modification date is indexed but there is no way to search for it. This is because DokuWiki has no API for creating localized date entry fields and I don't want to create such a field myself. 

