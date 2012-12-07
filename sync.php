<?php

  /*
    
    The sync.php script does manage the importation of files on the file system.
    A dataset can be composed of one or multiple files. One or multiple records are defined
    in each file.
    
    This scripts only check if a file has been modified on the file system and keep the
    index of files' states updated.
    
    Then "converters" are called by this script to perform specific conversion steps
    needed by different datasets.
  
  */

  // Reading the INI file to synch all setuped datasets
  
  /*
     Data structure of the INI file:
     
    Array
    (
        [AuthorClaim] => Array
            (
                [datasetURI] =>
                [datasetLocalPath] => 
                [converterPath] => 
            )
    )
   
   */

  $setup = parse_ini_file(getcwd()."/sync.ini", TRUE);  
  
  // Initiliaze needed resources to run this script
  ini_set("display_errors", "On");
  ini_set("memory_limit",$setup["config"]["memory"]."M");
  set_time_limit(65535);
  
  include_once(rtrim($setup["config"]["structwsfFolder"], "/")."/StructuredDynamics/SplClassLoader.php"); 
  
  // Load the \ws namespace where all the web service code is 
  $loader_ws = new SplClassLoader('StructuredDynamics\structwsf\php\api\ws', "/usr/share/structwsf/");
  $loader_ws->register();

  // Load the \framework namespace where all the supporting (utility) code is
  $loader_framework = new SplClassLoader('StructuredDynamics\structwsf\php\api\framework', "/usr/share/structwsf/");
  $loader_framework->register();

  // Load the \framework namespace where all the supporting (utility) code is
  $loader_core_framework = new SplClassLoader('StructuredDynamics\structwsf\framework', "/usr/share/structwsf/");
  $loader_core_framework->register();    
  
	// Sync all defined datasets
	foreach($setup as $datasetName => $dataset)
	{
    if($datasetName != "config")                                 
    {
		  $datasetName = preg_replace("/[^a-zA-Z0-9\-\s]/", "_", $datasetName);
		  $files = array();
		  $modifiedFiles = array(); // added or modified files
		  $hashIndexFile = $setup["config"]["indexesFolder"].$datasetName."hashIndex.md5";
		  $filesHash = array();
		  $updateFiles = array(); // array of paths+files to update
		  $hashFile = "";
		  $filesUpdated = array();

		  // Read the hashIndex file to check if files have been modified
		  
		  /*
		 	  The hash table file has this format:
		 	  
		 	  /local/file/path/:md5
		 	  
		  */
		  $hashIndex = @file_get_contents($hashIndexFile);
		  
		  if($hashIndex)
		  {
			  $hashIndex = explode("\n", $hashIndex);
			  
			  foreach($hashIndex as $hashRow)
			  {
				  $hash = explode(":", $hashRow);
				  if($hash[0] != "" && $hash[1] != "")
				  {
					  $filesHash[$hash[0]] = $hash[1];
				  }
			  }
			  
			  // Free memory of the hash index
			  $hashIndex = NULL;
		  }

		  // Get all the path+files within all directories of the dataset folder
		  readDirectory($dataset["datasetLocalPath"], $files);
      
      // Check for a filtering pattern.
      if(isset($dataset["filteredFilesRegex"]))
      {
        foreach($files as $f)
        {
          $file = $f[0];
          
          if(preg_match("/".$dataset["filteredFilesRegex"]."/", $f[1]) > 0)
          {
            $modified = FALSE;
            
            // Check if the file is new
            if(isset($filesHash[$file]))
            {
              // Check if the file as been modified
              if(md5_file($file) != $filesHash[$file])
              {
                $modified = TRUE;
                
                // Update the hash table that we will re-write later
                $filesHash[$file] = md5_file($file);
              }
            }
            else
            {
              // New file
              $modified = TRUE;
              
              // Update the hash table that we will re-write later
              $filesHash[$file] = md5_file($file);
            }
            
            // Mark as modified if forceReloadSolrIndexed or forceReload
            // is specified for this dataset
            if(strtolower($dataset["forceReload"]) == "true" ||
               strtolower($dataset["forceReloadSolrIndexed"]) == "true" )
            {
              $modified = TRUE;             
            }
            
            // If the file as been added/modified, we re-index in structWSF
            if($modified)
            {
              // Check for a date-stamp that we will use for sorting purposes.              
              preg_match("/\.(.*)\.rdf\.xml/", $f[1], $matches);
              
              $key = -1;
              
              if(count($matches) > 0)
              {
                $key = preg_replace("/[^0-9]*/", "", $matches[1]);
              }
              
              if($key != -1)
              {
                $updateFiles[$key] = $file;
              }
              else
              {
                array_push($updateFiles, $file);
              }
            }            
          }
        }
      }
      else
      {
        // Get possible filters                              
        $filteredFiles = explode(";", $dataset["filteredFiles"]);

        foreach($files as $f)
        {
          $file = $f[0];
          
          if(is_array($filteredFiles) && array_search($f[1], $filteredFiles) === FALSE)
          {
            continue;
          }
          
          $modified = FALSE;
          // Check if the file is new
          if(isset($filesHash[$file]))
          {
            // Check if the file as been modified
            if(md5_file($file) != $filesHash[$file])
            {
              $modified = TRUE;
              
              // Update the hash table that we will re-write later
              $filesHash[$file] = md5_file($file);
            }
          }
          else
          {
            // New file
            $modified = TRUE;
            
            // Update the hash table that we will re-write later
            $filesHash[$file] = md5_file($file);
          }
          
          // Mark as modified if forceReloadSolrIndexed or forceReload
          // is specified for this dataset
          if(strtolower($dataset["forceReload"]) == "true" ||
             strtolower($dataset["forceReloadSolrIndexed"]) == "true" )
          {
            $modified = TRUE;             
          }
          
          // If the file as been added/modified, we re-index in structWSF
          if($modified)
          {
            array_push($updateFiles, $file);
          }
        }        
      }
      
      // Order files by their timestamp; if not available, then this step doesn't matter
      ksort($updateFiles, SORT_NUMERIC);

		  // Lets re-write the hash index
		  foreach($filesHash as $filePath => $md5)
		  {
			  $hashFile .= $filePath.":".$md5."\n";
		  }
		  
		  // Update all added/modified files
		  if(count($updateFiles) > 0)
		  {
			  include_once($dataset["converterPath"].$dataset["converterScript"]);
			  
			  foreach($updateFiles as $key => $updateFile)
			  {
          // Propagate the global setting to the dataset's settings.
          $setup[$datasetName]["structwsfFolder"] = $setup["config"]["structwsfFolder"];
          $setup[$datasetName]["ontologiesStructureFiles"] = $setup["config"]["ontologiesStructureFiles"];
          $setup[$datasetName]["missingVocabulary"] = $setup["config"]["missingVocabulary"];
          
				  // This is needed in case of name collision; Namespaces are only supported in PHP 6
				  call_user_func($dataset["converterFunctionName"], $updateFile, $dataset, $setup[$datasetName]);
				  
          echo "File updated: $updateFile\n";  
			  }
		  }
		  
		  // save the new hash index file
		  file_put_contents($hashIndexFile, $hashFile);
	  }
  }

	function readDirectory($path, &$files)
	{
		$h = opendir($path);
		
		if($h)
		{
			while ($file = readdir($h)) 
			{
				if($file != "." && $file != ".." && strpos($file, "converted") === FALSE)
				{
  				array_push($files, array($path."$file", $file));
				}
			}
		
			closedir($h);
		}		
  }

?>