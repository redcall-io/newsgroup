<?php
require __DIR__ . '/vendor/autoload.php';
require "Volunteer.php";
require "GoogleGroupSubscription.php";

use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;
use Google\Cloud\Logging\LoggingClient;
use Google\Cloud\Logging\PsrLogger;

//to avoid warning in the logs
function getArrayElement($key, $array)
{
  if(!array_key_exists($key, $array))
    return null;

  return $array[$key];
}

function deleteGoogleGroupSubscription(Google_Service_Directory $service, string $MAILING_LIST, ?Volunteer $volunteer, GoogleGroupSubscription $subscription, array $doNotDeleteList, PsrLogger $logger)
{
  if(!in_array($subscription->email, $doNotDeleteList))
  { //if it's in the $doNotDeleteList, we skip the deletion
    try
    {
      $logger->debug("Deleting google group subscription", [$volunteer, $subscription]);
      $service->members->delete($MAILING_LIST, $subscription->email);
    }
    catch (Exception $e)
    {
      $logger->error("Error while deleting a user of the group",[$e,$volunteer,$subscription]);
    }
  }
  else
  {
    $logger->debug("Skipping deletion of google group subscription as it's in doNotDeleteList", [$subscription, $doNotDeleteList]);
  }
}


try
{
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

if (!getenv('MAILING_LIST') || !getenv('EMAIL_TO_IMPERSONATE'))
{
  throw new \LogicException('MAILING_LIST env var must be defined');
}

if ( !$cron )
{
  // Prod environment, direct access
  $token_get = getArrayElement('t', $_GET);
  $token     = getenv('TOKEN');

  if($token_get !== $token)
  {
    die('I am a cron job, not to be invoked directly');
  }
}

  $logger = LoggingClient::psrBatchLogger(
    "Firestore2GGroup", [
    'resource'=>[
      'type'=>'gae_app'
    ],
    'labels'  =>null
  ]);



$MAILING_LIST         = getenv ('MAILING_LIST');
$EMAIL_TO_IMPERSONATE = getenv ('EMAIL_TO_IMPERSONATE');
$DO_NOT_DELETE        = explode("," , getenv('DO_NOT_DELETE'));

$logger->info("starting sync", [$MAILING_LIST, $EMAIL_TO_IMPERSONATE, $DO_NOT_DELETE]);


$db         = new FirestoreClient();
$ref        = $db->collection('pegass');
$volunteers = [];
$snapshot   = $ref->documents();

foreach ($snapshot as $doc => $entity) {
  /** @var DocumentSnapshot $entity */
//    if (!$entity->offsetExists('nivol')) {
//        $ref->document($entity->id())->delete();
//    }

  $volunteer = Volunteer::fromEntity($entity);
  try
  {
    $volunteers[$volunteer->getSubscribedEmail()] = $volunteer;
  }
  catch(UnexpectedValueException $unexpectedValueException)
  {
     $logger->debug("Incomplete profile for volunteer", [$unexpectedValueException, $unexpectedValueException->volunteer]);
  }
}


  $client = new Google_Client();
  $client->setApplicationName('Pegass2GGroup');
  $client->useApplicationDefaultCredentials(true);
  $client->addScope(
    [
      Google_Service_Directory::ADMIN_DIRECTORY_GROUP,
      Google_Service_Directory::ADMIN_DIRECTORY_GROUP_MEMBER
    ]);
  $client->setSubject($EMAIL_TO_IMPERSONATE);

  /** @var GoogleGroupSubscription[] $subscriptions */
  $subscriptions = [];
  $service = new Google_Service_Directory($client);
  $members = $service->members->listMembers($MAILING_LIST);

  /** @var $member  Google_Service_Directory_Member */
  foreach ($members as $member)
  {
    $subscription = GoogleGroupSubscription::fromGoogleGroups($member);
    $subscriptions[$subscription->email]=$subscription;

    $volunteer =  getArrayElement($subscription->email, $volunteers);
    if(!$volunteer)
    {
      $logger->debug("Volunteer not found for a google group subscription, deleting subscription", [$subscription]);
      deleteGoogleGroupSubscription($service, $MAILING_LIST, null, $subscription, $DO_NOT_DELETE, $logger);
    }
  }

  $actions = [];
  foreach ($volunteers as $volunteer)
  {
    $subscription = getArrayElement($volunteer->getSubscribedEmail(), $subscriptions);
    if($subscription)
    {
      if($subscription->status == "ACTIVE" && $subscription->delivery_settings=="ALL_MAIL")
      {
        if($volunteer->enabled && $volunteer->subscribed)
        {
          $logger->debug("Volunteer is in sync & subscribed",[$volunteer,$subscription]);
        }
        else
        {
          $logger->debug("Volunteer is out of sync {inactive in firestore & active in google group}, removing subscription in GG",[$volunteer,$subscription]);
          deleteGoogleGroupSubscription($service, $MAILING_LIST, $volunteer, $subscription, $DO_NOT_DELETE, $logger);
        }
      }
      else
      {
        if($volunteer->enabled && $volunteer->subscribed)
        {
          $logger->debug("Volunteer is out of sync {Active in firestore & exist but inactive in google group}, ?updating subscription in GG?",[$volunteer,$subscription]);
        }
        else
        {
          $logger->debug("Volunteer is in sync & unsubscribed",[$volunteer,$subscription]);
        }

      }
    }
    else
    {
      if($volunteer->enabled && $volunteer->subscribed)
      {
        $logger->debug("Volunteer is in out of sync {active & subscribed in firestore, does not exists in Google Group} -> adding to Google Groups",[$volunteer,$subscription]);
        $new_member = new Google_Service_Directory_Member();
        $new_member->setEmail($volunteer->getSubscribedEmail());
        $new_member->setDeliverySettings("ALL_MAIL");
        $new_member->setRole("MEMBER");

        try
        {
          if("christophe.jossa@croix-rouge.fr" == $volunteer->getSubscribedEmail())
            $service->members->insert($MAILING_LIST, $new_member);
        }
        catch (Exception $e)
        {
          $logger->error("Error while inserting a user of the group",[$e,$volunteer, $new_member]);
        }
      }
      else
      {
        $logger->debug("Volunteer is in sync & unsubscribed",[$volunteer,$subscription]);
      }
    }
  }


}
catch(Exception $e)
{
  print_r($e);
}


