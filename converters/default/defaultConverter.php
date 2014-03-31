<?php
  
/*
  This default converter does check if a file is to be split in multiple chuncks
  before getting imported into the OSF Web Services. The input file has to be in RDF+XML, 
  no actual conversion is performed with this default converter.
  
  Warning: make sure that the folder where you *big* files (hundred of mbytes or gigs)
           are accessble by Virtuoso. Check the DirsAllowed parameter in the
           Virtuoso config file.
*/

use \StructuredDynamics\osf\ws\framework\DBVirtuoso;
use \StructuredDynamics\osf\ws\framework\WebService;
use \StructuredDynamics\osf\framework\WebServiceQuerier;
use \StructuredDynamics\osf\ws\framework\ClassHierarchy;
use \StructuredDynamics\osf\ws\framework\ClassNode;
use \StructuredDynamics\osf\ws\framework\PropertyHierarchy;
use \StructuredDynamics\osf\ws\framework\propertyNode;
use \StructuredDynamics\osf\php\api\ws\crud\create\CrudCreateQuery;
use \StructuredDynamics\osf\php\api\ws\crud\update\CrudUpdateQuery;
use \StructuredDynamics\osf\php\api\ws\crud\delete\CrudDeleteQuery;
use \StructuredDynamics\osf\php\api\ws\dataset\delete\DatasetDeleteQuery;
use \StructuredDynamics\osf\php\api\ws\dataset\read\DatasetReadQuery;
use \StructuredDynamics\osf\php\api\ws\dataset\create\DatasetCreateQuery;
use \StructuredDynamics\osf\php\api\ws\auth\lister\AuthListerQuery;
use \StructuredDynamics\osf\php\api\ws\auth\registrar\access\AuthRegistrarAccessQuery;
use \StructuredDynamics\osf\php\api\framework\CRUDPermission;
use \StructuredDynamics\osf\framework\Namespaces;

include_once('inc/exportDataset.php');                        

// Initiliaze needed resources to run this script

function defaultConverter($file, $dataset, $setup = array())
{  
  cecho("Importing dataset: ".cecho($setup["datasetURI"], 'UNDERSCORE', TRUE)."\n\n", 'CYAN');
  
  // Create credentials array
  $credentials = array(
    'osf-web-services' => $dataset["targetOSFWebServices"],
    'application-id' => $setup["credentials"]["application-id"],
    'api-key' => $setup["credentials"]["api-key"],
    'user' => $setup["credentials"]["user"],
  );  
  
  /*
    We have to split it. The procesure is simple:
    
    (1) we index the big file into a temporary Virtuoso graph
    (2) we get 100 records to index at a time
    (3) we index the records slices using CRUD: Update
    (4) we delete the temporary graph
  */

  $revisionsDataset = rtrim($setup["datasetURI"], '/').'/revisions/';
  
  $importDataset = rtrim($setup["datasetURI"], '/').'/import';
  
  if(isset($dataset['forceReloadSolrIndex']) && 
     strtolower($dataset['forceReloadSolrIndex']) == 'true')
  {
    $importDataset = $dataset['datasetURI'];
  }
  
  // Create a connection to the triple store
  $osf_ini = parse_ini_file(WebService::$osf_ini . "osf.ini", TRUE);

  $db = new DBVirtuoso($osf_ini["triplestore"]["username"], $osf_ini["triplestore"]["password"],
                       $osf_ini["triplestore"]["dsn"], $osf_ini["triplestore"]["host"]); 

                       
  // Check if the dataset is existing, if it doesn't, we try to create it
  $datasetRead = new DatasetReadQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
  
  $datasetRead->uri($setup["datasetURI"])
              ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
  
  $newDataset = FALSE;
           
  if(!$datasetRead->isSuccessful())
  {      
    if($datasetRead->error->id == 'WS-DATASET-READ-304')
    {
      // not existing, so we create it       
      $datasetCreate = new DatasetCreateQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
      
      $datasetCreate->creator((isset($dataset['creator']) ? $dataset['creator'] : ''))
                    ->uri($dataset["datasetURI"])
                    ->description((isset($dataset['description']) ? $dataset['description'] : ''))
                    ->title((isset($dataset['title']) ? $dataset['title'] : ''))
                    ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
                    
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
        // Create the initial access permissions for the input group

        // Get the list of registered web services
        $authLister = new AuthListerQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
        
        $authLister->getRegisteredWebServiceEndpointsUri()
                   ->mime('resultset')
                   ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
        
        if(!$authLister->isSuccessful())      
        {
          $debugFile = md5(microtime()).'.error';
          file_put_contents('/tmp/'.$debugFile, var_export($authLister, TRUE));
               
          @cecho('Can\'t get the list of registered web services to create the permissions for: '.$dataset["datasetURI"].'. '. $authLister->getStatusMessage() . 
               $authLister->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
          
          exit(1);
        } 
        
        $webservices = array();
        
        $resultset = $authLister->getResultset()->getResultset();
        
        foreach($resultset['unspecified'] as $list)
        {
          foreach($list['http://www.w3.org/1999/02/22-rdf-syntax-ns#li'] as $ws)
          {
            $webservices[] = $ws['uri'];
          }
        }
        
        // Register the credentials      
        $authRegistrarAccess = new AuthRegistrarAccessQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
        
        $crudPermissions = new CRUDPermission(TRUE, TRUE, TRUE, TRUE);
        
        if(!is_array($dataset['groups']))
        {
          $dataset['groups'] = array($dataset['groups']);
        }
        
        foreach($dataset['groups'] as $group)
        {
          $authRegistrarAccess->create($group, $dataset["datasetURI"], $crudPermissions, $webservices)
                              ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
          
          if(!$authRegistrarAccess->isSuccessful())      
          {
            $debugFile = md5(microtime()).'.error';
            file_put_contents('/tmp/'.$debugFile, var_export($authRegistrarAccess, TRUE));
                 
            @cecho('Can\'t create permissions for this new dataset: '.$dataset["datasetURI"].'. '. $authRegistrarAccess->getStatusMessage() . 
                 $authRegistrarAccess->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
                 
            exit(1);
          }          
        }
        
        $newDataset = TRUE;
        
        cecho('Dataset not existing, successfully created: '.$dataset["datasetURI"]."\n", 'MAGENTA');
      }
    }
  }
  
                         
  if(isset($dataset['forceReloadSolrIndex']) &&
     strtolower($dataset['forceReloadSolrIndex']) == 'true')
  {
    cecho('Reloading dataset in Solr: '.$dataset["datasetURI"]."\n", 'MAGENTA');
  }
                     
  // If we want to reload the dataset, we first delete it in the OSF Web Services
  if(isset($dataset['forceReload']) &&
     strtolower($dataset['forceReload']) == 'true' &&
     $newDataset === FALSE)
  {
    cecho('Reloading dataset: '.$dataset["datasetURI"]."\n", 'MAGENTA');
    
    // First we get information about the dataset (creator, title, description, etc)
    $datasetRead = new DatasetReadQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
    
    $datasetRead->uri($setup["datasetURI"])
                ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
             
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
      
      // Before deleting the dataset, we have to get all the access permissions
      // defined for any groups for this dataset.
      $datasetRecord = $datasetRead->getResultset()->getResultset();
      $datasetRecord = $datasetRecord['unspecified'][$setup["datasetURI"]];
      
      $authLister = new AuthListerQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
      
      $authLister->getDatasetGroupsAccesses($dataset["datasetURI"])
                 ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));

      if(!$authLister->isSuccessful())
      {      
        $debugFile = md5(microtime()).'.error';
        file_put_contents('/tmp/'.$debugFile, var_export($authLister, TRUE));
        
        @cecho('Can\'t get dataset groups accesses permissions. '. $authLister->getStatusMessage() . 
             $authLister->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
             
        exit(1);
      }                
      
      cecho('Dataset accesses read: '.$dataset["datasetURI"]."\n", 'MAGENTA');
      
      $accessRecords = $authLister->getResultset()->getResultset();
      $accessRecords = $accessRecords['unspecified'];
      
      // Then we delete it
      $datasetDelete = new DatasetDeleteQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
      
      $datasetDelete->uri($setup["datasetURI"])
                    ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));

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
        
        // Then we re-create it
        $datasetCreate = new DatasetCreateQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
        
        $datasetCreate->creator($datasetRecord[Namespaces::$dcterms.'creator'][0]['uri'])
                      ->uri($setup["datasetURI"])
                      ->description($datasetRecord['description'])
                      ->title($datasetRecord['prefLabel'])
                      ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
                      
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
          // Finally we re-create the access permissions that were existing for the dataset 
          // before it gets deleted.
          
          foreach($accessRecords as $accessRecord)
          {
            $authRegistrarAccess = new AuthRegistrarAccessQuery($setup["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
            
            $create = filter_var($accessRecord['http://purl.org/ontology/wsf#create'][0]['value'], FILTER_VALIDATE_BOOLEAN);
            $read = filter_var($accessRecord['http://purl.org/ontology/wsf#read'][0]['value'], FILTER_VALIDATE_BOOLEAN);
            $update = filter_var($accessRecord['http://purl.org/ontology/wsf#update'][0]['value'], FILTER_VALIDATE_BOOLEAN);
            $delete = filter_var($accessRecord['http://purl.org/ontology/wsf#delete'][0]['value'], FILTER_VALIDATE_BOOLEAN);
            $group = $accessRecord['http://purl.org/ontology/wsf#groupAccess'][0]['uri'];
            
            $webservices = array();
            
            foreach($accessRecord['http://purl.org/ontology/wsf#webServiceAccess'] as $ws)
            {
              array_push($webservices, $ws['uri']);
            }

            $crudPermissions = new CRUDPermission($create, $read, $update, $delete);
            
            $authRegistrarAccess->create($group, $dataset["datasetURI"], $crudPermissions, $webservices)
                                ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
            
            if(!$authRegistrarAccess->isSuccessful())      
            {
              $debugFile = md5(microtime()).'.error';
              file_put_contents('/tmp/'.$debugFile, var_export($authRegistrarAccess, TRUE));
                   
              @cecho('Can\'t create permissions for this reloaded dataset: '.$dataset["datasetURI"].'. '. $authRegistrarAccess->getStatusMessage() . 
                   $authRegistrarAccess->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
                   
              exit(1);
            }    
          }
          
          cecho('Dataset re-created: '.$dataset["datasetURI"]."\n", 'MAGENTA');
        }
      }    
    }
    
    echo "\n";
  }                         

  // Start by deleting the import graph that may have been left over.
  if(!isset($dataset['forceReloadSolrIndex']) ||
     strtolower($dataset['forceReloadSolrIndex']) == 'false' &&
     $newDataset === FALSE)
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
    
    // Import the revisions graph
    if(stripos($file, ".n3") !== FALSE)
    {      
      $sqlQuery = "DB.DBA.TTLP_MT(file_to_string_output('".getRevisionsFilePath($file)."'),'".$revisionsDataset."','".$revisionsDataset."')";
    }
    else
    {
      $sqlQuery = "DB.DBA.RDF_LOAD_RDFXML_MT(file_to_string_output('".getRevisionsFilePath($file)."'),'".$revisionsDataset."','".$revisionsDataset."')";
    }
    
    $resultset = $db->query($sqlQuery);
    
    if(odbc_error())
    {
      cecho("Error: can't import the revisions file: ".getRevisionsFilePath($file).", into the triple store  [".odbc_errormsg()."]\n", 'RED');
      
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
    
    $osf_ini = parse_ini_file(WebService::$osf_ini . "osf.ini", TRUE);
    
    $ch = curl_init();        

    curl_setopt($ch, CURLOPT_URL, $osf_ini['triplestore']['host'].":".$osf_ini['triplestore']['port']."/sparql/");
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Accept: application/sparql-results+json", "Accept-Charset: utf-8"));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "default-graph-uri=".urlencode($importDataset)."&query=".urlencode($sparqlQuery)."&format=".urlencode("application/sparql-results+json")."&debug=on");      
    curl_setopt($ch, CURLOPT_HEADER, TRUE);            
    
    $json_data = curl_exec($ch);    
    
    if($json_data === FALSE)
    {
    }
    
    $header = substr($json_data, 0, strpos($json_data, "\r\n\r\n"));
    
    $data = substr($json_data, strpos($json_data, "\r\n\r\n") + 4, strlen($json_data) - (strpos($json_data, "\r\n\r\n") - 4));
    
    curl_close($ch);    
    
    $resultset = json_decode($data);
    
    $crudAction = "create";

    foreach($resultset->results->bindings as $binding) 
    {
      $s = "";
      $p = "";
      $o = "";
      $olang = "";
      $otype = "";      
      
      if(isset($binding->o))
      {
        $o = $binding->o->value;                
      }
      
      if(isset($binding->s))
      {
        $s = $binding->s->value;
      }
      
      if(isset($binding->p))
      {
        $p = $binding->p->value;
      }
      
      if(isset($binding->olang))
      {
        $olang = $binding->olang->value;
      }
      
      if(isset($binding->otype))
      {
        $otype = $binding->otype->value;
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
          if($olang != "")
          {
            $subjectDescription .= "<$s> <$p> \"\"\"".n3Encode($o)."\"\"\"@$olang .\n";
          }
          elseif($otype != 'http://www.w3.org/2001/XMLSchema#string')
          {
            $subjectDescription .= "<$s> <$p> \"\"\"".n3Encode($o)."\"\"\"^^<$otype>.\n";
          }
          else
          {
            $subjectDescription .= "<$s> <$p> \"\"\"".n3Encode($o)."\"\"\" .\n";
          }
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
      
      $crudCreate = new CrudCreateQuery($dataset["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
      
      $crudCreate->dataset($dataset["datasetURI"])
                 ->documentMimeIsRdfN3()
                 ->document($crudCreates);
                 
      if(isset($dataset['forceReloadSolrIndex']) &&
         strtolower($dataset['forceReloadSolrIndex']) == 'true')
      {
        $crudCreate->enableSearchIndexationMode();                       
      }
      else
      {
        $crudCreate->enableFullIndexationMode();
      }
                 
      $crudCreate->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
      
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
      
      $crudUpdate = new CrudUpdateQuery($dataset["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
      
      $crudUpdate->dataset($dataset["datasetURI"])
                 ->documentMimeIsRdfN3()
                 ->document($crudUpdates)
                 ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
                 
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
        $crudDelete = new CrudDeleteQuery($dataset["targetOSFWebServices"], $credentials['application-id'], $credentials['api-key'], $credentials['user']);
        
        $crudDelete->dataset($setup["datasetURI"])
                   ->uri($uri)
                   ->send((isset($dataset['targetOSFWebServicesQueryExtension']) ? new $dataset['targetOSFWebServicesQueryExtension'] : NULL));
        
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

    $osf_ini = parse_ini_file(WebService::$osf_ini . "osf.ini", TRUE);
    
    $ch = curl_init();        

    curl_setopt($ch, CURLOPT_URL, $osf_ini['triplestore']['host'].":".$osf_ini['triplestore']['port']."/sparql/");
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

    $osf_ini = parse_ini_file(WebService::$osf_ini . "osf.ini", TRUE);
    
    $ch = curl_init();        

    curl_setopt($ch, CURLOPT_URL, $osf_ini['triplestore']['host'].":".$osf_ini['triplestore']['port']."/sparql/");
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
