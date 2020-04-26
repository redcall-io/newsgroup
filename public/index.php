<?php

if(array_key_exists('action', $_GET))
{
  $action = $_GET['action'];

  if($action == 'PEGASS')
  {
    include __DIR__ . "/Pegass2Firestore.php";
  }
  else if ($action == 'GGROUP')
  {
    echo "GGroup";
    include __DIR__ . "/Firestore2GoogleGroup.php";
  }
}
else
{
  die("What action should I do, Master ?");
}
