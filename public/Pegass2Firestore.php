<?php

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/PegassClient.php';
require __DIR__ . '/Volunteer.php';
require __DIR__ . '/NewsGroup.php';

use Google\Cloud\Firestore\DocumentSnapshot;

class Pegass2Firestore extends NewsGroup
{
  /**
   * @var string $ulId
   */
  private $ulId;
  
  public function __construct()
  {
    parent::__construct();
    $this->getEnvVars();
    $this->initPegassClient();
  }

  private function getEnvVars()
  {
    if (!getenv('UL_ID'))
    {
      throw new \LogicException('UL_ID & TOKEN env var must be defined');
    }
    $this->ulId = getenv('UL_ID');
  }

  /**
   *
   * @return Volunteer[] array of volunteers.
   */
  private function getVolunteersFromPegass()
  {
    $serviceAccount = __DIR__ . '/../service-account.json';
    if (file_exists($serviceAccount))
    {
      // Development environment
      putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $serviceAccount);
      $pages = json_decode(file_get_contents('mock.json'), true);
    }
    else
    {
      // Prod environment, cron job
      $pages = $this->pegassClient->getVolunteers($this->ulId);
    }

    // Filter out everyone's emails from pegass result
    $volunteerList = [];
    foreach ($pages as $page)
    {
      foreach ($page['list'] as $pegassVolunteer)
      {
        $volunteer                         = new Volunteer();
        $volunteer->nivol                  = ltrim($pegassVolunteer['id'], '0');
        $volunteer->emails                 = $this->pegassClient->fetchEmails($pegassVolunteer['coordonnees']);
        $volunteer->enabled                = $pegassVolunteer['actif'  ];
        $volunteer->first_name             = $pegassVolunteer['prenom' ];
        $volunteer->last_name              = $pegassVolunteer['nom'    ];
        $volunteer->subscribed             = true;
        $volunteer->out_of_sync            = false;
        $volunteer->subscribed_email_index = 0;
        $volunteerList[$volunteer->nivol]  = $volunteer;
      }
    }
    return $volunteerList;
  }

  /**
   * Retrieve Volunteers from Firestore
   */
  private function getFirestoreContent()
  {

    $ref              = $this->getFirestoreCollection();
    $firestoreContent = [];
    $snapshot         = $ref->documents();

    foreach ($snapshot as $doc => $entity)
    {
      $firestoreContent[$entity['nivol']] = $entity;
    }
    return $firestoreContent;
  }

   /**
    * Retrieve the volunteers from Pegass & put/update it in Firestore
    */
  public function run()
  {
    $volunteerList    = $this->getVolunteersFromPegass();
    $firestoreContent = $this->getFirestoreContent    ();
    $ref              = $this->getFirestoreCollection ();

// Disable all volunteers from firestore that are missing in pegass
    $missing = array_diff(array_keys($firestoreContent), array_keys($volunteerList));
    foreach ($missing as $nivol)
    {
      echo "disable ", $nivol, PHP_EOL;

      /** @var DocumentSnapshot $entity */
      $entity = $firestoreContent[$nivol];
      $ref->document($entity->id())->update
      ([
        ['path' => 'enabled', 'value' => false],
      ]);
    }

// Create all volunteers that are missing in firestore
    $new = array_diff(array_keys($volunteerList), array_keys($firestoreContent));
    foreach ($new as $nivol)
    {
      echo "create ", $nivol, PHP_EOL;

      $volunteer = $volunteerList[$nivol];
      $ref->newDocument()->set([
        'emails'      => $volunteer->emails,
        'enabled'     => $volunteer->enabled,
        'nivol'       => $volunteer->nivol,
        'first_name'  => ucwords(strtolower($volunteer->first_name)),
        'last_name'   => ucwords(strtolower($volunteer->last_name)),
        'subscribed'  => true,
        'out_of_sync' => false,
        'subscribed_email_index' => 0,
      ]);
    }

// Update all volunteers that are in firestore
    foreach (array_intersect(array_keys($volunteerList), array_keys($firestoreContent)) as $nivol) {
      $volunteer = $volunteerList[$nivol];
      /** @var DocumentSnapshot $entity */
      $entity = $firestoreContent[$nivol];

      $storeEmails = $entity['emails'];
      sort($storeEmails);
      $pegassEmails = $volunteer->emails;
      sort($pegassEmails);

      if ($volunteer->enabled !== $entity['enabled']
        || json_encode($pegassEmails) !== json_encode($storeEmails)) {
        echo "update ", $nivol, PHP_EOL;

        $ref->document($entity->id())->update([
          ['path' => 'emails' , 'value' => $volunteer->emails ],
          ['path' => 'enabled', 'value' => $volunteer->enabled],
        ]);
      }
    }

  }
}

echo "<pre>";
$pegass2Firestore = null;
try
{
  $pegass2Firestore = new Pegass2Firestore();
  $pegass2Firestore->run();
}
catch(Exception $e)
{
  print_r($e);
  echo PHP_EOL,PHP_EOL,PHP_EOL;
  print_r($pegass2Firestore);
}

echo "</pre>";
