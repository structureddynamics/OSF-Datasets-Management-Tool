<?php

  use \StructuredDynamics\structwsf\php\api\ws\dataset\read\DatasetReadQuery;

  function getDatasets($structwsf)
  {
    $datasetRead = new DatasetReadQuery($structwsf);
    
    $datasetRead->excludeMeta()
                ->uri('all')
                ->send();
    
    var_dump($datasetRead);
    
    if($datasetRead->isSuccessful())
    {
      $resultset = $datasetRead->getResultset()->getResultset();

      $datasets = array();
      
      foreach($resultset['unspecified'] as $uri => $dataset)
      {
        $dset = array(
          'uri' => '',
          'label' => '',
          'description' => '',
          'created' => '',
          'modified' => ''
        );        

        $dset['uri'] = $uri;
        $dset['label'] = $dataset['prefLabel'];
        
        if(isset($dataset['description']))
        {
          $dset['created'] = $dataset['description'];
        }
        
        if(isset($dataset['http://purl.org/dc/terms/created']))
        {
          $dset['created'] = $dataset['http://purl.org/dc/terms/created'][0]['value'];
        }
        
        if(isset($dataset['http://purl.org/dc/terms/modified']))
        {
          $dset['modified'] = $dataset['http://purl.org/dc/terms/modified'][0]['value'];
        }

        array_push($datasets, $dset);        
      }
      return($datasets);
    }
    else
    {
      $debugFile = md5(microtime()).'.error';
      file_put_contents('/tmp/'.$debugFile, var_export($datasetRead, TRUE));
     
      @cecho('Can\'t get accessible datasets. '. $datasetRead->getStatusMessage() . 
             $datasetRead->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
             
      exit(1);
    }   
  }
  
  function showDatasets($datasets)
  {
    $nb = 0;
    
    cecho("Datasets: \n", 'WHITE');
    
    foreach($datasets as $dataset)
    {
      $nb++;
      
      cecho("  ($nb) ".$dataset['label'].' '.cecho('('.$dataset['uri'].')', 'CYAN', TRUE)."\n");
    }
  }
  
?>
