<?php
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Volunteer.php';
require __DIR__ . '/NewsGroup.php';
require __DIR__ . '/GoogleGroupSubscription.php';

/**
 * Sync Firestore with Google Groups
 *
 * Google API Docs :
 * https://github.com/googleapis/google-api-php-client
 * https://developers.google.com/admin-sdk/directory/v1/reference/members#resource
 * 
 *
 */
class Firestore2GoogleGroup extends NewsGroup
{
  /**
   * @var string $mailingList
   */
  private $mailingList;

  /**
   * @var string[] $doNotDeleteList
   */
  private $doNotDeleteList;

  public function __construct()
  {
    echo "GGroup-parent constructor";
    parent::__construct();
    echo "1";
    $this->logger->debug("parent constructore done");
    $this->getEnvVars();
    echo "2";
    $this->logger->debug("env var done");
    $this->initGoogleClient();
    echo "3";
    $this->logger->debug("google client init done");
    $this->initGoogleServiceDirectoryService();
    echo "4";
    $this->logger->debug("google service directory done");
  }

  private function getEnvVars()
  {
    if (!getenv('MAILING_LIST') && !getenv('DO_NOT_DELETE'))
    {
      throw new \LogicException('MAILING_LIST env var must be defined');
    }
    $this->mailingList      = getenv ('MAILING_LIST');
    $this->doNotDeleteList  = explode("," , getenv('DO_NOT_DELETE'));
  }

  private function deleteGoogleGroupSubscription(?Volunteer $volunteer, GoogleGroupSubscription $subscription)
  {
    if(!in_array($subscription->email, $this->doNotDeleteList))
    { //if it's in the $doNotDeleteList, we skip the deletion
      try
      {
        echo "deleting ";
        print_r($subscription);
        echo PHP_EOL;

        $this->logger->debug("Deleting google group subscription", [$volunteer, $subscription]);
        $this->googleDirectoryService->members->delete($this->mailingList, $subscription->email);
      }
      catch (Exception $e)
      {
        $this->logger->error("Error while deleting a user of the group",[$e, $volunteer, $subscription]);
      }
    }
    else
    {
      $this->logger->debug("Skipping deletion of google group subscription as it's in doNotDeleteList", [$subscription, $this->doNotDeleteList]);
    }
  }

   //to avoid warning in the logs
  private function getArrayElement($key, $array)
  {
    if(!array_key_exists($key, $array))
      return null;

    return $array[$key];
  }


  /**
   * Retrieve the volunteers from firestore & put/update it in Google Groups
   */
  public function run()
  {
    $this->logger->info("starting sync firestore->GoogleGroups", [$this->mailingList, $this->emailToImpersonate, $this->doNotDeleteList]);

    $ref        = $this->getFirestoreCollection();
    $volunteers = [];
    $snapshot   = $ref->documents();

    foreach ($snapshot as $doc => $entity)
    {
      $volunteer = Volunteer::fromEntity($entity);
      try
      {
        $volunteers[$volunteer->getSubscribedEmail()] = $volunteer;
      }
      catch(UnexpectedValueException $unexpectedValueException)
      {
        $this->logger->debug("Incomplete profile for volunteer", [$unexpectedValueException, $unexpectedValueException->volunteer]);
      }
    }


    /** @var GoogleGroupSubscription[] $subscriptions */
    $subscriptions = [];
    $members = $this->googleDirectoryService->members->listMembers($this->mailingList);

    //list google subscription and deleting it when the volunteer can't be found by its email, or is disabled or unsubscribed in firestore
    /** @var $member  Google_Service_Directory_Member */
    foreach ($members as $member)
    {
      $subscription = GoogleGroupSubscription::fromGoogleGroups($member);
      $subscriptions[$subscription->email]=$subscription;
      /** @var Volunteer $volunteer*/
      $volunteer =  $this->getArrayElement($subscription->email, $volunteers);
      if(!$volunteer || !$volunteer->enabled || !$volunteer->subscribed)
      {
        $this->logger->debug("Volunteer not found in firestore (or disabled or unsubscribed) for a google group subscription, deleting subscription", [$subscription, $volunteer]);
        $this->deleteGoogleGroupSubscription( $volunteer, $subscription);
      }
    }

    //list the firestore volunteers
    foreach ($volunteers as $volunteer)
    {
      $subscription = $this->getArrayElement($volunteer->getSubscribedEmail(), $subscriptions);
      if($subscription)
      {
        if($subscription->status == "ACTIVE" && $subscription->delivery_settings=="ALL_MAIL")
        {
          if($volunteer->enabled && $volunteer->subscribed)
          {
            $this->logger->debug("Volunteer is in sync & subscribed",[$volunteer, $subscription]);
          }
          else
          {
            $this->logger->debug("Volunteer is out of sync {inactive in firestore & active in google group}, removing subscription in GG",[$volunteer,$subscription]);
            $this->deleteGoogleGroupSubscription($volunteer, $subscription);
          }
        }
        else
        {
          if($volunteer->enabled && $volunteer->subscribed)
          {
            $this->logger->debug("Volunteer is out of sync {Active in firestore & exist but inactive in google group}, ?updating subscription in GG?",[$volunteer,$subscription]);
          }
          else
          {
            $this->logger->debug("Volunteer is in sync & unsubscribed",[$volunteer, $subscription]);
          }

        }
      }
      else
      {
        if($volunteer->enabled && $volunteer->subscribed)
        {
          $this->logger->debug("Volunteer is in out of sync {active & subscribed in firestore, does not exists in Google Group} -> adding to Google Groups",[$volunteer,$subscription]);
          $new_member = new Google_Service_Directory_Member();
          $new_member->setEmail           ($volunteer->getSubscribedEmail());
          $new_member->setDeliverySettings("ALL_MAIL");
          $new_member->setRole            ("MEMBER");

          try
          {
            if("christophe.jossa@croix-rouge.fr" == $volunteer->getSubscribedEmail())
            {
              echo "inserting ";
              print_r($subscription);
              echo PHP_EOL;
              $this->googleDirectoryService->members->insert($this->mailingList, $new_member);
            }

          }
          catch (Exception $e)
          {
            $this->logger->error("Error while inserting a user of the group",[$e, $volunteer, $new_member]);
          }
        }
        else
        {
          $this->logger->debug("Volunteer is in sync & unsubscribed",[$volunteer, $subscription]);
        }
      }
    }
  }
}
echo "<pre>";

$firestore2GoogleGroups = null;
try
{
  $firestore2GoogleGroups = new Firestore2GoogleGroup();
  $firestore2GoogleGroups->run();
}
catch(Exception $e)
{
  print_r($e);
  echo PHP_EOL,PHP_EOL,PHP_EOL;
  print_r($firestore2GoogleGroups);
}

echo "</pre>";
