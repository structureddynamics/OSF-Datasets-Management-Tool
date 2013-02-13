<?php
  function cecho($text, $color="NORMAL", $return = FALSE)
  {
    $_colors = array(
      'LIGHT_RED'    => "[1;31m",
      'LIGHT_GREEN'  => "[1;32m",
      'YELLOW'       => "[1;33m",
      'LIGHT_BLUE'   => "[1;34m",
      'MAGENTA'      => "[1;35m",
      'LIGHT_CYAN'   => "[1;36m",
      'WHITE'        => "[1;37m",
      'NORMAL'       => "[0m",
      'BLACK'        => "[0;30m",
      'RED'          => "[0;31m",
      'GREEN'        => "[0;32m",
      'BROWN'        => "[0;33m",
      'BLUE'         => "[0;34m",
      'CYAN'         => "[0;36m",
      'BOLD'         => "[1m",
      'UNDERSCORE'   => "[4m",
      'REVERSE'      => "[7m",
    );    
    
    $out = $_colors["$color"];
    
    if($out == "")
    { 
      $out = "[0m"; 
    }
    
    if($return)
    {
      return(chr(27)."$out$text".chr(27)."[0m");
    }
    else
    {
      echo chr(27)."$out$text".chr(27).chr(27)."[0m";
    }
  }
  
  function getInput($msg)
  {
    fwrite(STDOUT, cecho("$msg: ", 'LIGHT_GREEN', TRUE));
    $varin = trim(fgets(STDIN));
    return $varin;
  }    
  
  function oecho($lines)
  {
    foreach($lines as $line)
    {
      cecho($line."\n", 'BLUE');
    }
  }
?>
