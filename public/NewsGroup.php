<?php

use Google\Cloud\Firestore\CollectionReference;
use Google\Cloud\Logging\LoggingClient;
use Google\Cloud\Logging\PsrLogger;
use Google\Cloud\Firestore\FirestoreClient;


abstract class NewsGroup
{
  /** @var string $firestoreCollectionName the name of the firestore collection*/
  protected $firestoreCollectionName;

  /** @var string $emailToImpersonate for Google Client: acting as this email*/
  protected $emailToImpersonate;


  /** @var FirestoreClient $firestoreClient the firestore client*/
  protected $firestoreClient;

  /** @var Google_Client $googleClient the google client*/
  protected $googleClient;

  /** @var Google_Service_Directory $googleDirectoryService the google service that allow to manipulate google groups subscriptions*/
  protected $googleDirectoryService;

  /** @var PegassClient $pegassClient the pegass client*/
  protected $pegassClient;

  /** @var PsrLogger $logger */
  protected $logger;

  private static $GOOGLE_CLIENT_APP_NAME="Pegass2GoogleGroup";

  /**
   * Checks if it's not running in Cron and there's no token passed, kill the php engine.
   * Otherwise :
   * Init some mandatory env vars
   * Init firestore connection
   */
  public function __construct()
  {
    if (!array_key_exists('X_APPENGINE_CRON', $_SERVER) || 'true' !== $_SERVER['X_APPENGINE_CRON'])
    {
      // Prod environment, direct access
      $token_get = $_GET['t'];
      $token     = getenv('TOKEN');

      if($token_get !== $token)
      {
        die('I am a cron job, not to be invoked directly');
      }
    }

    $this->initLogger   ();
    $this->getEnvVars   ();
    $this->initFirestore();

  }

  private function getEnvVars()
  {
    $this->firestoreCollectionName = $this->getEnv("FIRESTORE_COLLECTION", false, true);
    $this->emailToImpersonate      = $this->getEnv("EMAIL_TO_IMPERSONATE", false, true);
  }

  /**
   * initialise the logger
   */
  protected function initLogger()
  {
    $this->logger = LoggingClient::psrBatchLogger(
      "NewsGroup", [
      'resource'=>[
        'type'=>'gae_app'
      ],
      'labels'  =>null
    ]);
  }

  /**
   * create the firestore client
   */
  protected function initFirestore()
  {
    $this->firestoreClient  = new FirestoreClient();
  }

  /**
   * @return CollectionReference the reference to the Firestore collection of the project
   */
  protected function getFirestoreCollection()
  {
    return $this->firestoreClient->collection($this->firestoreCollectionName);
  }

  /**Create the Google Client*/
  protected function initGoogleClient()
  {
    $client = new Google_Client();
    $client->setApplicationName(NewsGroup::$GOOGLE_CLIENT_APP_NAME);
    $client->useApplicationDefaultCredentials(true);
    $client->addScope(
      [
        Google_Service_Directory::ADMIN_DIRECTORY_GROUP,
        Google_Service_Directory::ADMIN_DIRECTORY_GROUP_MEMBER
      ]);
    $client->setSubject($this->emailToImpersonate);

    $this->googleClient = $client;
  }

  protected function initGoogleServiceDirectoryService()
  {
    $this->googleDirectoryService =  new Google_Service_Directory($this->googleClient);
  }

  protected function initPegassClient()
  {
    $this->pegassClient = new PegassClient();
  }

  /**
   * @param string $envVarName  the name of the var we want to get
   * @param bool $isFrom_SERVER if true, the var is get from $_SERVER, if false from getEnv()
   * @param bool $throwExceptionOnMissingEnv if the var is missing (array_key_exists return false or getEnv return null), throw an exception
   * @return string the value of the env var
   */
  protected function getEnv(string $envVarName, bool $isFrom_SERVER, bool $throwExceptionOnMissingEnv)
  {
    if($isFrom_SERVER)
    {
      if(!array_key_exists($envVarName, $_SERVER))
      {
        if($throwExceptionOnMissingEnv)
        {
          throw new \LogicException( "Mandatory env var missing (\$_SERVER)", [$envVarName]);
        }
        else
        {
          return null;
        }
      }
      return $_SERVER[$envVarName];
    }
    else
    {
      if (!getenv($envVarName))
      {
        if($throwExceptionOnMissingEnv)
        {
          throw new \LogicException( "Mandatory env var missing (getEnv()) for env var name:'$envVarName'");
        }
        else
        {
          return null;
        }
      }
      return getenv($envVarName);
    }
  }
}
