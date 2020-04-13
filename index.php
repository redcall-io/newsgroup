<?php

if(array_key_exists('action', $_GET))
{
  $action = $_GET['action'];

  if($action == 'PEGASS')
  {
    include "pegass2firestore.php";
  }
  else if ($action == 'GGROUP')
  {
    include "firestore2ggroup.php";
  }
}
else
{
  die("What action should I do, Master ?");
}
