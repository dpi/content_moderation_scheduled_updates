<?php

namespace Drupal\content_moderation_scheduled_updates\Plugin\Validation\Constraint;

use Drupal\content_moderation_scheduled_updates\CmsuUtilityInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\content_moderation\ModerationInformationInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Checks if a moderation state transition is valid.
 */
class CmsuScheduledStateTransitionValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The moderation info.
   *
   * @var \Drupal\content_moderation\ModerationInformationInterface
   */
  protected $moderationInformation;

  /**
   * CMSU utilities.
   *
   * @var \Drupal\content_moderation_scheduled_updates\CmsuUtilityInterface
   */
  protected $cmsuUtility;

  /**
   * Contains a map of scheduled update types which change moderation_state.
   *
   * Keys contain scheduled update type ID, values are the name of the field
   * on the scheduled update entity containing new state values. If value is 
   * null then the type does not map to content moderation field.
   *
   * @var array
   */
  protected $moderationStateFieldMap = [];

  /**
   * Creates a new CmsuScheduledStateTransitionValidator instance.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   * @param \Drupal\content_moderation\ModerationInformationInterface $moderationInformation
   *   The moderation information.
   * @param  \Drupal\content_moderation_scheduled_updates\CmsuUtilityInterface
   *   CMSU utilities.
   */
  public function __construct(EntityTypeManagerInterface $entityTypeManager, ModerationInformationInterface $moderationInformation, CmsuUtilityInterface $cmsuUtility) {
    $this->entityTypeManager = $entityTypeManager;
    $this->moderationInformation = $moderationInformation;
    $this->cmsuUtility = $cmsuUtility;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('content_moderation.moderation_information'),
      $container->get('cmsu.utility')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function validate($value, Constraint $constraint) {
    /** @var \Drupal\content_moderation_scheduled_updates\Plugin\Validation\Constraint\CmsuScheduledStateTransition $constraint */
    /** @var \Drupal\Core\Entity\ContentEntityInterface $entity */
    $entity = $value->getEntity();

    // A list of states.
    // Each value is an array with keys time and state.
    $stateTimeline = [];

    $newStateAfterSave = $entity->moderation_state->value ?? NULL;
    if ($newStateAfterSave) {
      $stateTimeline[] = ['time' => 0, 'state' => $newStateAfterSave];
    }

    array_push($stateTimeline, ...$this->getScheduledStateTransitions($entity));

    usort($stateTimeline, function($a, $b) {
      return $a['time'] > $b['time'];
    });

    $stateTimelinePairs = array_map(
      NULL,
      array_slice($stateTimeline, 0, -1),
      array_slice($stateTimeline, 1)
    );

    $workflow = $this->moderationInformation->getWorkflowForEntity($entity);
    foreach ($stateTimelinePairs as $stateTimelinePair) {
      [$from, $to] = $stateTimelinePair;

      try {
        $stateFrom = $workflow->getTypePlugin()->getState($from['state']);
        $stateTo = $workflow->getTypePlugin()->getState($to['state']);
      }
      catch (\InvalidArgumentException $e) {
        // If either state does not exist.
        continue;
      }

      $canTransition = $stateFrom->canTransitionTo($stateTo->id());
      if (!$canTransition) {
        $transitionDate = DrupalDateTime::createFromTimestamp($to['time']);
        $this->context->addViolation($constraint->messageInvalidTransition, [
          '%date' => $transitionDate->format('r'),
          '%from' => $stateFrom->label(),
          '%to' => $stateTo->label(),
        ]);
      }
    }
  }

  /**
   * Get a list of scheduled state transitions.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $entity
   *   The entity which scheduled updates are associated.
   *
   * @return array
   *   An unordered array containing time and state machine name.
   */
  public function getScheduledStateTransitions(ContentEntityInterface $entity): array {
    $scheduledUpdateReferenceFields = $this->cmsuUtility
      ->getScheduledUpdateReferenceFields($entity->getEntityTypeId(), $entity->bundle());

    $timeline = [];
    foreach ($scheduledUpdateReferenceFields as $fieldName) {
      foreach ($entity->{$fieldName} as $item) {
        /** @var \Drupal\scheduled_updates\ScheduledUpdateInterface $scheduledUpdateEntity */
        $scheduledUpdateEntity = $item->entity;

        // Does this scheduled update change moderation state?
        $sourceToStateField = $this->getModerationStateFieldName($scheduledUpdateEntity->bundle());
        if (!$sourceToStateField) {
          continue;
        }

        // Does the scheduled update contain a value?
        $targetState = $scheduledUpdateEntity->{$sourceToStateField}->value ?? '';
        if (empty($targetState)) {
          continue;
        }

        $timeline[] = [
          'time' => $scheduledUpdateEntity->update_timestamp->value ?? NULL,
          'state' => $targetState,
        ];
      }
    }

    return $timeline;
  }

  /**
   * Get the field which contains new state values for a scheduled update type.
   *
   * @param string $scheduledUpdateTypeId
   *   ID of a scheduled update type entity.
   *
   * @return string|null
   *   The name of the field, or null.
   */
  function getModerationStateFieldName(string $scheduledUpdateTypeId): ?string {
    if (array_key_exists($scheduledUpdateTypeId, $this->moderationStateFieldMap)) {
      return $this->moderationStateFieldMap[$scheduledUpdateTypeId];
    }

    /** @var \Drupal\scheduled_updates\ScheduledUpdateTypeInterface|null $scheduledUpdateType */
    $scheduledUpdateType = $this->entityTypeManager
      ->getStorage('scheduled_update_type')
      ->load($scheduledUpdateTypeId);

    $fieldName = array_search('moderation_state', $scheduledUpdateType->getFieldMap());
    $fieldName = $fieldName ? $fieldName : NULL;
    $this->moderationStateFieldMap[$scheduledUpdateTypeId] = $fieldName;

    return $this->moderationStateFieldMap[$scheduledUpdateTypeId];
  }

}
