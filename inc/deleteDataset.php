<?php

  use \StructuredDynamicsosf\php\api\ws\dataset\delete\DatasetDeleteQuery;

  /**
  * Delete a Dataset from a OSF Web Service instance
  * 
  * @param mixed $uri URI of the dataset to delete
  * @param mixed $osfWebServices URL of the OSF Web Services network
  * 
  * @return Return FALSE if the dataset couldn't be delete. Return TRUE otherwise.
  */
  function deleteDataset($uri, $osfWebServices)
  {
    $datasetDelete = new DatasetDeleteQuery($osfWebServices);
    
    $datasetDelete->uri($uri)
                  ->send();  
    
    if($datasetDelete->isSuccessful())
    {
      cecho("Dataset successfully deleted: $uri\n", 'CYAN');
    }
    else
    {
      $debugFile = md5(microtime()).'.error';
      file_put_contents('/tmp/'.$debugFile, var_export($datasetDelete, TRUE));
           
      @cecho('Can\'t delete dataset '.$uri.'. '. $datasetDelete->getStatusMessage() . 
           $datasetDelete->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
           
      return(FALSE);
    }
    
    return(TRUE);    
  }
?>
