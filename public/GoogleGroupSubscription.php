<?php

use Google\Cloud\Firestore\DocumentSnapshot;

/**
 * Mirror the object https://developers.google.com/admin-sdk/directory/v1/reference/members#resource
 * 
 */
class GoogleGroupSubscription
{
  /**
  Defines mail delivery preferences of member. This is only supported by create/update/get.

  Acceptable values are:
  "ALL_MAIL": All messages, delivered as soon as they arrive.
  "DAILY": No more than one message a day.
  "DIGEST": Up to 25 messages bundled into a single message.
  "DISABLED": Remove subscription.
  "NONE": No messages.
   * @var string $delivery_settings
   */
  public $delivery_settings;
  
  /**
   * @var string $email
   */
  public $email;
  /**
   * @var string $etag
   */
  public $etag;
  /**
   * @var string $id
   */
  public $id;
  /**
   * @var string $role
   */
  public $role;
  /**
   * @var string $status
   */
  public $status;
  /**
   * @var string $type
   */
  public $type;

  /**
   * @param Google_Service_Directory_Member $googleGroupMember the google group member retrieved from Google Group
   * @return GoogleGroupSubscription The local representation of the google group
   */
  public static function fromGoogleGroups(Google_Service_Directory_Member $googleGroupMember)
  {
    $suscription = new GoogleGroupSubscription();

    $suscription->delivery_settings = $googleGroupMember["delivery_settings"];
    $suscription->email             = $googleGroupMember["email"];
    $suscription->etag              = $googleGroupMember["etag"];
    $suscription->id                = $googleGroupMember["id"];
    $suscription->role              = $googleGroupMember["role"];
    $suscription->status            = $googleGroupMember["status"];
    $suscription->type              = $googleGroupMember["type"];
    
    return $suscription;
  }

 
}
