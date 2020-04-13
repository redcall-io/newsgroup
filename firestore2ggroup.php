<?php
require __DIR__ . '/vendor/autoload.php';

use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;


/**
 *
 * API Docs : https://github.com/googleapis/google-api-php-client
 *
 */


if(array_key_exists('X_APPENGINE_CRON', $_SERVER) && 'true' === $_SERVER['X_APPENGINE_CRON'])
{
  $cron = true;
}
else
{
  $cron = false;
}

if (!getenv('MAILING_LIST'))
{
  throw new \LogicException('MAILING_LIST env var must be defined');
}



if ( !$cron )
{
  // Prod environment, direct access
  $token_get = $_GET['t'];
  $token     = getenv('TOKEN');

  if($token_get !== $token)
  {
    die('I am a cron job, not to be invoked directly');
  }
}

$MAILING_LIST = getenv('MAILING_LIST');

$db       = new FirestoreClient();
$ref      = $db->collection('pegass');
$store    = [];
$snapshot = $ref->documents();

echo "<pre>";
foreach ($snapshot as $doc => $entity) {
//    /** @var DocumentSnapshot $entity */
//    if (!$entity->offsetExists('nivol')) {
//        $ref->document($entity->id())->delete();
//    }
  print_r($entity['nivol']);
  echo PHP_EOL;
  $store[$entity['nivol']] = $entity;
}

echo "</pre><hr><pre>";

try
{
  $client = new Google_Client();
  $client->setApplicationName('Pegass2GGroup');
  $client->setScopes([Google_Service_Directory::ADMIN_DIRECTORY_GROUP,Google_Service_Directory::ADMIN_DIRECTORY_USER]);
  $client->useApplicationDefaultCredentials(true);
  $access_token = $client->fetchAccessTokenWithAssertion();

  print_r($access_token);

  $service = new Google_Service_Directory($client);

  /** @var $members  Google_Service_Directory_Members */
  $members = $service->members->listMembers($MAILING_LIST);

  /** @var $member  Google_Service_Directory_Member */
  foreach ($members as $member)
  {
    print_r($member->getEmail());
  }
  echo "</pre>";
}
catch(Exception $e)
{
  print_r($e);
  echo "</pre>";
}


