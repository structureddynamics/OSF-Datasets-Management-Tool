<?php
  
/*
  This default converter does check if a file is to be split in multiple chuncks
  before getting imported into structWSF. The input file has to be in RDF+XML, 
  no actual conversion is performed with this default converter.
  
  Warning: make sure that the folder where you *big* files (hundred of mbytes or gigs)
           are accessble by Virtuoso. Check the DirsAllowed parameter in the
           Virtuoso config file.
*/

use \StructuredDynamics\structwsf\ws\framework\DBVirtuoso;
use \StructuredDynamics\structwsf\ws\framework\WebService;
use \StructuredDynamics\structwsf\framework\WebServiceQuerier;
use \StructuredDynamics\structwsf\ws\framework\ClassHierarchy;
use \StructuredDynamics\structwsf\ws\framework\ClassNode;
use \StructuredDynamics\structwsf\ws\framework\PropertyHierarchy;
use \StructuredDynamics\structwsf\ws\framework\propertyNode;
use \StructuredDynamics\structwsf\php\api\ws\crud\create\CrudCreateQuery;
use \StructuredDynamics\structwsf\php\api\ws\crud\update\CrudUpdateQuery;
use \StructuredDynamics\structwsf\php\api\ws\crud\delete\CrudDeleteQuery;
use \StructuredDynamics\structwsf\php\api\ws\dataset\delete\DatasetDeleteQuery;
use \StructuredDynamics\structwsf\php\api\ws\dataset\read\DatasetReadQuery;
use \StructuredDynamics\structwsf\php\api\ws\dataset\create\DatasetCreateQuery;
use \StructuredDynamics\structwsf\framework\Namespaces;
use \StructuredDynamics\structwsf\php\api\framework\CRUDPermission;


// Initiliaze needed resources to run this script

function defaultConverter($file, $dataset, $setup = array())
{  
  cecho("Importing dataset: ".cecho($setup["datasetURI"], 'UNDERSCORE', TRUE)."\n\n", 'CYAN');
  
  /*
    We have to split it. The procesure is simple:
    
    (1) we index the big file into a temporary Virtuoso graph
    (2) we get 100 records to index at a time
    (3) we index the records slices using CRUD: Update
    (4) we delete the temporary graph
  */
  
  $importDataset = rtrim($setup["datasetURI"], '/').'/import';
  
  if(isset($dataset['forceReloadSolrIndex']) && 
     strtolower($dataset['forceReloadSolrIndex']) == 'true')
  {
    $importDataset = $dataset['datasetURI'];
  }
  
  // Create a connection to the triple store
  $data_ini = parse_ini_file(WebService::$data_ini . "data.ini", TRUE);

  $db = new DBVirtuoso($data_ini["triplestore"]["username"], $data_ini["triplestore"]["password"],
                       $data_ini["triplestore"]["dsn"], $data_ini["triplestore"]["host"]); 

                       
  // Check if the dataset is existing, if it doesn't, we try to create it
  $datasetRead = new DatasetReadQuery($setup["targetStructWSF"]);
  
  $datasetRead->excludeMeta()
              ->uri($setup["datasetURI"])
              ->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));
           
  if(!$datasetRead->isSuccessful())
  {      
    if($datasetRead->error->id == 'WS-DATASET-READ-304')
    {
      // not existing, so we create it       
      $datasetCreate = new DatasetCreateQuery($setup["targetStructWSF"]);
      
      $datasetCreate->creator((isset($dataset['creator']) ? $dataset['creator'] : ''))
                    ->uri($dataset["datasetURI"])
                    ->description((isset($dataset['description']) ? $dataset['description'] : ''))
                    ->title((isset($dataset['title']) ? $dataset['title'] : ''))
                    ->globalPermissions(new CRUDPermission(FALSE, TRUE, FALSE, FALSE))
                    ->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));
                    
      if(!$datasetCreate->isSuccessful())
      {
        $debugFile = md5(microtime()).'.error';
        file_put_contents('/tmp/'.$debugFile, var_export($datasetCreate, TRUE));
             
        @cecho('Can\'t create the dataset for reloading it. '. $datasetCreate->getStatusMessage() . 
             $datasetCreate->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
             
        exit(1);        
      } 
      else
      {
        cecho('Dataset not existing, creating it: '.$dataset["datasetURI"]."\n", 'MAGENTA');
      }
    }
  }
  
                         
  if(isset($dataset['forceReloadSolrIndex']) &&
     strtolower($dataset['forceReloadSolrIndex']) == 'true')
  {
    cecho('Reloading dataset in Solr: '.$dataset["datasetURI"]."\n", 'MAGENTA');
  }
                     
  // If we want to reload the dataset, we first delete it in structWSF
  if(isset($dataset['forceReload']) &&
     strtolower($dataset['forceReload']) == 'true')
  {
    cecho('Reloading dataset: '.$dataset["datasetURI"]."\n", 'MAGENTA');
    
    // First we get information about the dataset (creator, title, description, etc)
    $datasetRead = new DatasetReadQuery($setup["targetStructWSF"]);
    
    $datasetRead->excludeMeta()
                ->uri($setup["datasetURI"])
                ->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));
             
    if(!$datasetRead->isSuccessful())
    {      
      $debugFile = md5(microtime()).'.error';
      file_put_contents('/tmp/'.$debugFile, var_export($datasetRead, TRUE));
      
      @cecho('Can\'t read the dataset for reloading it. '. $datasetRead->getStatusMessage() . 
           $datasetRead->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
           
      exit(1);
    }
    else
    {
      cecho('Dataset description read: '.$dataset["datasetURI"]."\n", 'MAGENTA');
      
      $datasetRecord = $datasetRead->getResultset()->getResultset();
      $datasetRecord = $datasetRecord['unspecified'][$setup["datasetURI"]];
      
      // Then we delete it
      $datasetDelete = new DatasetDeleteQuery($setup["targetStructWSF"]);
      
      $datasetDelete->uri($setup["datasetURI"])
                    ->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));

      if(!$datasetDelete->isSuccessful())
      {
        $debugFile = md5(microtime()).'.error';
        file_put_contents('/tmp/'.$debugFile, var_export($datasetDelete, TRUE));
        
        @cecho('Can\'t delete the dataset for reloading it. '. $datasetDelete->getStatusMessage() . 
             $datasetDelete->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
                
        exit(1);
      }
      else
      {
        cecho('Dataset deleted: '.$dataset["datasetURI"]."\n", 'MAGENTA');
        
        // Finally we re-create it
        $datasetCreate = new DatasetCreateQuery($setup["targetStructWSF"]);
        
        $datasetCreate->creator($datasetRecord[Namespaces::$dcterms.'creator'][0]['uri'])
                      ->uri($setup["datasetURI"])
                      ->description($datasetRecord['description'])
                      ->title($datasetRecord['prefLabel'])
                      ->globalPermissions(new CRUDPermission(FALSE, TRUE, FALSE, FALSE))
                      ->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));
                      
        if(!$datasetCreate->isSuccessful())
        {
          $debugFile = md5(microtime()).'.error';
          file_put_contents('/tmp/'.$debugFile, var_export($datasetCreate, TRUE));
               
          @cecho('Can\'t create the dataset for reloading it. '. $datasetCreate->getStatusMessage() . 
               $datasetCreate->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
               
          exit(1);        
        }                      
        else
        {
          cecho('Dataset re-created: '.$dataset["datasetURI"]."\n", 'MAGENTA');
        }
      }    
    }
    
    echo "\n";
  }                         

  // Start by deleting the import graph that may have been left over.
  if(!isset($dataset['forceReloadSolrIndex']) ||
     strtolower($dataset['forceReloadSolrIndex']) == 'false')
  {
    $sqlQuery = "sparql clear graph <".$importDataset.">";
    
    $resultset = $db->query($sqlQuery);

    if(odbc_error())
    {
      cecho("Error: can't delete the graph used for importing the file [".odbc_errormsg()."]\n", 'RED');
      
      return;
    }    
    
    unset($resultset);                               
                          
    // Import the big file into Virtuoso  
    if(stripos($file, ".n3") !== FALSE)
    {      
      $sqlQuery = "DB.DBA.TTLP_MT(file_to_string_output('".$file."'),'".$importDataset."','".$importDataset."')";
    }
    else
    {
      $sqlQuery = "DB.DBA.RDF_LOAD_RDFXML_MT(file_to_string_output('".$file."'),'".$importDataset."','".$importDataset."')";
    }
    
    $resultset = $db->query($sqlQuery);
    
    if(odbc_error())
    {
      cecho("Error: can't import the file: $file, into the triple store  [".odbc_errormsg()."]\n", 'RED');
      
      return;
    }    
    
    unset($resultset);     
  }

  // count the number of records
  $sparqlQuery = "
  
    select count(distinct ?s) as ?nb from <".$importDataset.">
    where
    {
      ?s a ?o .
    }
  
  ";

  $resultset = $db->query($db->build_sparql_query($sparqlQuery, array ('nb'), FALSE));
  
  $nb = odbc_result($resultset, 1);

  unset($resultset);
  
  $nbRecordsDone = 0;

  while($nbRecordsDone < $nb && $nb > 0)
  {
    // Create slices of records
    $sparqlQuery = "
      
      select ?s ?p ?o (DATATYPE(?o)) as ?otype (LANG(?o)) as ?olang
      where 
      {
        {
          select distinct ?s from <".$importDataset."> 
          where 
          {
            ?s a ?type.
          } 
          limit ".$setup["sliceSize"]." 
          offset ".$nbRecordsDone."
        } 
        
        ?s ?p ?o
      }
    
    ";

    $crudCreates = '';
    $crudUpdates = '';
    $crudDeletes = array();
    
    $rdfDocumentN3 = "";
    
    $start = microtime_float(); 
    
    $currentSubject = "";
    $subjectDescription = "";
    
    $network_ini = parse_ini_file(WebService::$network_ini . "network.ini", TRUE);
    
    $ch = curl_init();        

    curl_setopt($ch, CURLOPT_URL, $network_ini["network"]["wsf_base_url"].":8890/sparql/");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/sparql-results+xml"));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "default-graph-uri=".urlencode($importDataset)."&query=".urlencode($sparqlQuery)."&format=".urlencode("application/sparql-results+xml")."&debug=on");      
    curl_setopt($ch, CURLOPT_HEADER, TRUE);            
    
    $xml_data = curl_exec($ch);    
    
    if($xml_data === FALSE)
    {
    }
    
    $header = substr($xml_data, 0, strpos($xml_data, "\r\n\r\n"));
    
    $data = substr($xml_data, strpos($xml_data, "\r\n\r\n") + 4, strlen($xml_data) - (strpos($xml_data, "\r\n\r\n") - 4));
    
    curl_close($ch);    
    
    $resultset = new SimpleXMLElement($data);

    $crudAction = "create";
    
    foreach($resultset->results->result as $result) 
    {
      $s = "";
      $p = "";
      $o = "";
      $olang = "";
      $otype = "";
        
      foreach($result->binding as $binding)
      {            
        switch((string)$binding["name"])
        {
          case "s":
            $s = (string)$binding->uri;
          break;
          case "p":
            $p = (string)$binding->uri;
          break;
          case "o":
            if($binding->uri)
            {
              $o = (string)$binding->uri;                
            }
            else
            {
              $o = (string)$binding->literal;                                  
            }              
          break;
          case "olang":
            $olang = (string)$binding->literal;
          break;
          case "otype":
            $otype = (string)$binding->uri;
          break;
        }
      }
        
      if($s != $currentSubject)
      {
        switch(strtolower($crudAction))
        {
          case "update":
            $crudUpdates .= $subjectDescription;
          break;
          
          case "delete":
            array_push($crudDeletes, $currentSubject);
          break;
          
          case "create":
          default:
            $crudCreates .= $subjectDescription;
          break;
        } 
        
        $subjectDescription = ""; 
        $crudAction = "create";
        $currentSubject = $s;                      
      }
      
      // Check to see if a "crudAction" property/value has been defined for this record. If not,
      // then we simply consider it as "create"
      if($p != "http://purl.org/ontology/wsf#crudAction")
      {
        if($otype != "" || $olang != "")
        {
          $subjectDescription .= "<$s> <$p> \"\"\"".n3Encode($o)."\"\"\" .\n";
        }
        else
        {
          $subjectDescription .= "<$s> <$p> <$o> .\n";
        }
      }
      else
      {
        switch(strtolower($o))
        {
          case "update":
            $crudAction = "update";
          break;
          
          case "delete":
            $crudAction = "delete";
          break;
          
          case "create":
          default:
            $crudAction = "create";
          break;
        }            
      }          
    }        
    
    // Add the last record that got processed above
    switch(strtolower($crudAction))
    {
      case "update":
        $crudUpdates .= $subjectDescription;
      break;
      
      case "delete":
        array_push($crudDeletes, $currentSubject);
      break;
      
      case "create":
      default:
        $crudCreates .= $subjectDescription;
      break;
    }         
          
    $end = microtime_float(); 
    
    cecho('Create N3 file(s): ' . round($end - $start, 3) . ' seconds'."\n", 'WHITE');   
    
    unset($resultset);
    
    if($crudCreates != "")
    {
      $crudCreates = "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n\n".$crudCreates;
      
      $start = microtime_float(); 
      
      $crudCreate = new CrudCreateQuery($dataset["targetStructWSF"]);
      
      $crudCreate->dataset($dataset["datasetURI"])
                 ->documentMimeIsRdfN3()
                 ->document($crudCreates)
                 ->registeredIp('self');
                 
      if(isset($dataset['forceReloadSolrIndex']) &&
         strtolower($dataset['forceReloadSolrIndex']) == 'true')
      {
        $crudCreate->enableSearchIndexationMode();                       
      }
      else
      {
        $crudCreate->enableFullIndexationMode();
      }
                 
      $crudCreate->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));
      
      if(!$crudCreate->isSuccessful())
      {
        $debugFile = md5(microtime()).'.error';
        file_put_contents('/tmp/'.$debugFile, var_export($crudCreate, TRUE));
             
        @cecho('Can\'t commit (CRUD Create) a slice to the target dataset. '. $crudCreate->getStatusMessage() . 
             $crudCreate->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
      }
      
      $end = microtime_float(); 
      
      if(isset($dataset['forceReloadSolrIndex']) &&
         strtolower($dataset['forceReloadSolrIndex']) == 'true')
      {      
        cecho('Records created in Solr: ' . round($end - $start, 3) . ' seconds'."\n", 'WHITE');         
      }
      else
      {
        cecho('Records created in Virtuoso & Solr: ' . round($end - $start, 3) . ' seconds'."\n", 'WHITE');
      }
      
      unset($wsq);   
    }
    
    if($crudUpdates != "")
    {
      $crudUpdates = "@prefix rdfs: <http://www.w3.org/2000/01/rdf-schema#> .\n\n".$crudUpdates;      
      
      $start = microtime_float(); 
      
      $crudUpdate = new CrudUpdateQuery($dataset["targetStructWSF"]);
      
      $crudUpdate->dataset($dataset["datasetURI"])
                 ->documentMimeIsRdfN3()
                 ->document($crudUpdates)
                 ->registeredIp('self')
                 ->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));
                 
      if(!$crudUpdate->isSuccessful())
      {
        $debugFile = md5(microtime()).'.error';
        file_put_contents('/tmp/'.$debugFile, var_export($crudUpdate, TRUE));
             
        @cecho('Can\'t commit (CRUD Updates) a slice to the target dataset. '. $crudUpdate->getStatusMessage() . 
             $crudUpdate->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
      }                 
      
      $end = microtime_float(); 
      
      cecho('Records updated: ' . round($end - $start, 3) . ' seconds'."\n", 'WHITE');
      
      unset($wsq);   
    }
    
    if(count($crudDeletes) > 0)
    {
      $start = microtime_float(); 
      foreach($crudDeletes as $uri)
      {
        $crudDelete = new CrudDeleteQuery($dataset["targetStructWSF"]);
        
        $crudDelete->dataset($setup["datasetURI"])
                   ->uri($uri)
                   ->registeredIp('self')
                   ->send((isset($dataset['targetStructWSFQueryExtension']) ? new $dataset['targetStructWSFQueryExtension'] : NULL));
        
        if(!$crudDelete->isSuccessful())
        {
          $debugFile = md5(microtime()).'.error';
          file_put_contents('/tmp/'.$debugFile, var_export($crudDelete, TRUE));
               
          @cecho('Can\'t commit (CRUD Delete) a record to the target dataset. '. $crudDelete->getStatusMessage() . 
               $crudDelete->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
        }        
      }
      
      $end = microtime_float(); 

      cecho('Records deleted: ' . round($end - $start, 3) . ' seconds'."\n", 'WHITE');
      
      unset($wsq);               
    }

    
    $nbRecordsDone += $setup["sliceSize"];
    
    cecho("$nbRecordsDone/$nb records for file: $file\n", 'WHITE');
  }
  
  // Now check what are the properties and types used in this dataset, check which ones 
  // are existing in the ontology, and report the ones that are not defined in the loaded
  // ontologies.
  if(!isset($dataset['forceReloadSolrIndex']) ||
     strtolower($dataset['forceReloadSolrIndex']) == 'false')
  {  
    $usedProperties = array();
    $usedTypes = array();
    
    // Get used properties
    $sparqlQuery = "
      
      select distinct ?p from <".$importDataset.">
      where 
      {
        ?s ?p ?o .
      }
    
    ";

    $network_ini = parse_ini_file(WebService::$network_ini . "network.ini", TRUE);
    
    $ch = curl_init();        

    curl_setopt($ch, CURLOPT_URL, $network_ini["network"]["wsf_base_url"].":8890/sparql/");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/sparql-results+xml"));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "default-graph-uri=".urlencode($importDataset)."&query=".urlencode($sparqlQuery)."&format=".urlencode("application/sparql-results+xml")."&debug=on");      
    curl_setopt($ch, CURLOPT_HEADER, TRUE);            
    
    $xml_data = curl_exec($ch);            
    $header = substr($xml_data, 0, strpos($xml_data, "\r\n\r\n"));        
    $data = substr($xml_data, strpos($xml_data, "\r\n\r\n") + 4, strlen($xml_data) - (strpos($xml_data, "\r\n\r\n") - 4));        
    curl_close($ch);    
    
    $resultset = new SimpleXMLElement($data);
    
    foreach($resultset->results->result as $result) 
    {           
      foreach($result->binding as $binding)
      {            
        switch((string)$binding["name"])
        {
          case "p":
            $p = (string)$binding->uri;
            
            if(!in_array($p, $usedProperties))
            {
              array_push($usedProperties, $p);
            }
          break;
        }
      }
    }      
    
    // Get used types
    $sparqlQuery = "
      
      select distinct ?o from <".$importDataset.">
      where 
      {
        ?s a ?o .
      }
    
    ";

    $network_ini = parse_ini_file(WebService::$network_ini . "network.ini", TRUE);
    
    $ch = curl_init();        

    curl_setopt($ch, CURLOPT_URL, $network_ini["network"]["wsf_base_url"].":8890/sparql/");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/sparql-results+xml"));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "default-graph-uri=".urlencode($importDataset)."&query=".urlencode($sparqlQuery)."&format=".urlencode("application/sparql-results+xml")."&debug=on");      
    curl_setopt($ch, CURLOPT_HEADER, TRUE);            
    
    $xml_data = curl_exec($ch);            
    $header = substr($xml_data, 0, strpos($xml_data, "\r\n\r\n"));        
    $data = substr($xml_data, strpos($xml_data, "\r\n\r\n") + 4, strlen($xml_data) - (strpos($xml_data, "\r\n\r\n") - 4));        
    curl_close($ch);    
    
    $resultset = new SimpleXMLElement($data);
    
    foreach($resultset->results->result as $result) 
    {           
      foreach($result->binding as $binding)
      {            
        switch((string)$binding["name"])
        {
          case "o":
            $o = (string)$binding->uri;
            
            if(!in_array($o, $usedTypes))
            {
              array_push($usedTypes, $o);
            }
          break;
        }
      }
    }        

    // Now check to make sure that all the predicates and types are in the ontological structure.
    $undefinedPredicates = array();
    $undefinedTypes = array();
    
    $filename = $setup["ontologiesStructureFiles"] . 'classHierarchySerialized.srz';
    $f = fopen($filename, "r");
    $classHierarchy = fread($f, filesize($filename));
    $classHierarchy = unserialize($classHierarchy);
    fclose($f);

    $filename = $setup["ontologiesStructureFiles"] . 'propertyHierarchySerialized.srz';
    $f = fopen($filename, "r");
    $propertyHierarchy = fread($f, filesize($filename));
    $propertyHierarchy = unserialize($propertyHierarchy);
    fclose($f);
    
    foreach($usedProperties as $usedPredicate)
    {
      $found = FALSE;
      foreach($propertyHierarchy->properties as $property)
      {
        if($property->name == $usedPredicate)
        {
          $found = TRUE;
          break;
        }
      }
      
      if($found === FALSE)
      {
        array_push($undefinedPredicates, $usedPredicate);
      }
    }
    
    foreach($usedTypes as $type)
    {
      $found = FALSE;
      foreach($classHierarchy->classes as $class)
      {
        if($class->name == $type)
        {
          $found = TRUE;
          break;
        }
      }
      
      if($found === FALSE)
      {
        array_push($undefinedTypes, $type);
      }
    }      
    
    $filename = substr($file, strrpos($file, "/") + 1);
    $filename = substr($filename, 0, strlen($filename) - 3);
    
    file_put_contents($setup["missingVocabulary"].$filename.".undefined.types.log", implode("\n", $undefinedTypes));
    file_put_contents($setup["missingVocabulary"].$filename.".undefined.predicates.log", implode("\n", $undefinedPredicates));
     
    
    // Now delete the graph we used to import the file

    $sqlQuery = "sparql clear graph <".$importDataset.">";
    
    $resultset = $db->query($sqlQuery);

    if(odbc_error())
    {
      cecho("Error: can't delete the graph used for importing the file [".odbc_errormsg()."]\n", 'RED');
      
      return;
    }    
    
    unset($resultset);  
  }  
  
  $db->close(); 
  
  echo "\n";  
}

  
function microtime_float ()
{
    list ($msec, $sec) = explode(' ', microtime());
    $microtime = (float)$msec + (float)$sec;
    return $microtime;
}

function n3Encode($string)
{
  return(trim(str_replace(array( "\\" ), "\\\\", $string), '"'));
}
       
?>
