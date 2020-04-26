<?php

use Google\Cloud\Firestore\DocumentSnapshot;

class Volunteer
{
  /**
   * @var string $firestore_id
   */
  public $firestore_id;
  /**
   * @var string $nivol
   */
  public $nivol;
  /**
   * @var string $first_name
   */
  public $first_name;
  /**
   * @var string $last_name
   */
  public $last_name;
  /**
   * @var string[] $emails
   */
  public $emails = [];
  /**
   * if the person is an active volunteer at the red cross
   * @var boolean $enabled
   */
  public $enabled;
  /**
   * if the volunteer is subscribed to the google group.
   * a volunteer should be subscribed only if he/she's active.
   * @var boolean $subscribed
   */
  public $subscribed;

  /**
   * if the volunteer is marked as subscribed in firestore
   * but exists in google group and is not subscribed
   * (likely the volunteer used the google group function to unsubscribe)
   * then it's out of sync.
   *
   * we'll have to see what we do in this case.
   *
   * @var boolean $out_of_sync
   */
  public $out_of_sync;

  /**
   * @var int $subscribed_email_index
   */
  public $subscribed_email_index;

  /**
   * @param DocumentSnapshot $entity the entity retrieved from firestore
   * @return Volunteer the volunteer initialised with data from firestore
   */
  public static function fromEntity(DocumentSnapshot $entity)
  {
    $volunteer = new Volunteer();
    $volunteer->firestore_id            = $entity->reference()->id();
    $volunteer->nivol                   = $entity["nivol"];
    $volunteer->first_name              = $entity["first_name"];
    $volunteer->last_name               = $entity["last_name"];
    $volunteer->emails                  = $entity["emails"];
    $volunteer->enabled                 = $entity["enabled"];
    $volunteer->subscribed              = $entity["subscribed"];
    $volunteer->out_of_sync             = $entity["out_of_sync"];
    $volunteer->subscribed_email_index  = $entity["subscribed_email_index"];
    
    return $volunteer;
  }

  /**
   * @return string the email that should be subscribed to the mailing list
   * @throws UnexpectedValueException
   */
  public function getSubscribedEmail()
  {
    if(count($this->emails) == 0)
    {
      $exception  = new UnexpectedValueException("Invalid 'emails' value. It's has no value.");
      $exception->volunteer = $this;
      throw  $exception;
    }

    if($this->subscribed_email_index === null || $this->subscribed_email_index < 0 || $this->subscribed_email_index > count($this->emails))
    {
      $exception  = new UnexpectedValueException("Invalid 'subscribed_email_index' value. It's either null, <0 or greater than the emails array size.");
      $exception->volunteer = $this;
      throw  $exception;
    }
    
    return $this->emails[$this->subscribed_email_index];
  }
}
