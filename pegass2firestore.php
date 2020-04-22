<?php

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/PegassClient.php';
require __DIR__ . '/Volunteer.php';

use Google\Cloud\Firestore\DocumentSnapshot;
use Google\Cloud\Firestore\FirestoreClient;

if (!getenv('UL_ID') || !getenv('TOKEN'))
{
  throw new \LogicException('UL_ID & TOKEN env var must be defined');
}

$UL_ID = getenv('UL_ID');

$pegass = new PegassClient();
$serviceAccount = __DIR__ . '/service-account.json';
if (file_exists($serviceAccount))
{
    // Development environment
    putenv('GOOGLE_APPLICATION_CREDENTIALS=' . $serviceAccount);
    $pages = json_decode(file_get_contents('mock.json'), true);
}
elseif (!array_key_exists('X_APPENGINE_CRON', $_SERVER) || 'true' !== $_SERVER['X_APPENGINE_CRON'])
{
  // Prod environment, direct access
  $token_get = $_GET['t'];
  $token     = getenv('TOKEN');

  if($token_get === $token)
  {
    $pages = $pegass->getVolunteers($UL_ID);
  }
  else
  {
    die('I am a cron job, not to be invoked directly');
  }
}
else
{
    // Prod environment, cron job
    $pages = $pegass->getVolunteers($UL_ID);
}

echo "<pre>";
// Filter out everyone's emails from pegass result
$nivols = [];
foreach ($pages as $page) {
    foreach ($page['list'] as $volunteer) {
        $nivol              = new Volunteer();
        $nivol->nivol       = ltrim($volunteer['id'], '0');
        $nivol->emails      = $pegass->fetchEmails($volunteer['coordonnees']);
        $nivol->enabled     = $volunteer['actif'  ];
        $nivol->first_name  = $volunteer['prenom' ];
        $nivol->last_name   = $volunteer['nom'    ];
        $nivols[$nivol->nivol] = $nivol;
    }
}

// Fetch all available volunteers in Firestore
$db       = new FirestoreClient();
$ref      = $db->collection('pegass');
$store    = [];
$snapshot = $ref->documents();

foreach ($snapshot as $doc => $entity) {
//    /** @var DocumentSnapshot $entity */
//    if (!$entity->offsetExists('nivol')) {
//        $ref->document($entity->id())->delete();
//    }

    $store[$entity['nivol']] = $entity;
}

// Disable all volunteers from firestore that are missing in pegass
$missing = array_diff(array_keys($store), array_keys($nivols));
foreach ($missing as $nivol) {
    echo "disable ", $nivol, PHP_EOL;

    /** @var DocumentSnapshot $entity */
    $entity = $store[$nivol];
    $ref->document($entity->id())->update([
        ['path' => 'enabled', 'value' => false],
    ]);
}

// Create all volunteers that are missing in firestore
$new = array_diff(array_keys($nivols), array_keys($store));
foreach ($new as $nivol) {
    echo "create ", $nivol, PHP_EOL;

    /** @var Volunteer $volunteer */
    $volunteer = $nivols[$nivol];
    $ref->newDocument()->set([
        'emails' => $volunteer->emails,
        'enabled' => $volunteer->enabled,
        'nivol' => $volunteer->nivol,
        'first_name' => ucwords(strtolower($volunteer->first_name)),
        'last_name'  => ucwords(strtolower($volunteer->last_name)),
        'subscribed' => true,
        'out_of_sync' => false,
        'subscribed_email_index' => 0,

    ]);
}

// Update all volunteers that are in firestore
foreach (array_intersect(array_keys($nivols), array_keys($store)) as $nivol) {
    /** @var Volunteer $volunteer */
    $volunteer = $nivols[$nivol];
    /** @var DocumentSnapshot $entity */
    $entity = $store[$nivol];

    $storeEmails = $entity['emails'];
    sort($storeEmails);
    $pegassEmails = $volunteer->emails;
    sort($pegassEmails);

    if ($volunteer->enabled !== $entity['enabled']
        || json_encode($pegassEmails) !== json_encode($storeEmails)) {
        echo "update ", $nivol, PHP_EOL;

        $ref->document($entity->id())->update([
            ['path' => 'emails', 'value' => $volunteer->emails],
            ['path' => 'enabled', 'value' => $volunteer->enabled],
        ]);
    }
}

echo "</pre>";
