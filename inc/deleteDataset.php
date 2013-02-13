<?php

  use \StructuredDynamics\structwsf\php\api\ws\dataset\delete\DatasetDeleteQuery;

  /**
  * Delete a Dataset from a structWSF instance
  * 
  * @param mixed $uri URI of the dataset to delete
  * @param mixed $structwsf URL of the structWSF network
  * 
  * @return Return FALSE if the dataset couldn't be delete. Return TRUE otherwise.
  */
  function deleteDataset($uri, $structwsf)
  {
    $datasetDelete = new DatasetDeleteQuery($structwsf);
    
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
           
      @cecho('Can\'t delete ontology '.$uri.'. '. $datasetDelete->getStatusMessage() . 
           $datasetDelete->getStatusMessageDescription()."\nDebug file: /tmp/$debugFile\n", 'RED');
           
      return(FALSE);
    }
    
    return(TRUE);    
  }
?>
