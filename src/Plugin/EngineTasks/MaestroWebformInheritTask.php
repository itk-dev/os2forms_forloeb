<?php

namespace Drupal\os2forms_forloeb\Plugin\EngineTasks;

use Drupal\webform\Entity\WebformSubmission;
use Drupal\webform\WebformSubmissionForm;
use Drupal\maestro_webform\Plugin\EngineTasks\MaestroWebformTask;
use Drupal\maestro\Form\MaestroExecuteInteractive;
use Drupal\maestro\Engine\MaestroEngine;
use Drupal\Core\Form\FormStateInterface;
use Drupal\webform\Entity\Webform;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\ReplaceCommand;

/**
 * Maestro Webform Task Plugin for Multiple Submissions.
 *
 * @Plugin(
 *   id = "MaestroWebformInherit",
 *   task_description = @Translation("Maestro Webform task for multiple submissions."),
 * )
 */
class MaestroWebformInheritTask extends MaestroWebformTask {

  /**
   * Constructor.
   *
   * @param array $configuration
   *   The incoming configuration information from the engine execution.
   *   [0] - is the process ID
   *   [1] - is the queue ID
   *   The processID and queueID properties are defined in the MaestroTaskTrait.
   */
  public function __construct(array $configuration = NULL) {
    if (is_array($configuration)) {
      $this->processID = $configuration[0];
      $this->queueID = $configuration[1];
    }
  }

  /**
   * {@inheritDoc}
   */
  public function shortDescription() {
    return t('Webform with Inherited submission');
  }

  /**
   * {@inheritDoc}
   */
  public function description() {
    return $this->t('Webform with Inherited submission');
  }

  /**
   * {@inheritDoc}
   *
   * @see \Drupal\Component\Plugin\PluginBase::getPluginId()
   */
  public function getPluginId() {
    return 'MaestroWebformInherit';
  }

  /**
   * {@inheritDoc}
   */
  public function getTaskEditForm(array $task, $templateMachineName) {

    // We call the parent, as we need to add a field to the inherited form
    $form = parent::getTaskEditForm($task, $templateMachineName);
    $form['inherit_webform_unique_id'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Inherit Webform from:'),
      '#description' => $this->t('Put the unique identifier of the webform you want to inherit from (start-task=submission'),
      '#default_value' => $task['data']['inherit_webform_unique_id'] ?? '',
      '#required' => TRUE,
    ];
    return $form;
  }

  /**
   * {@inheritDoc}
   */
  public function prepareTaskForSave(array &$form, FormStateInterface $form_state, array &$task) {
    
    // Inherit from parent
    parent::prepareTaskForSave($form, $form_state, $task);
    // Add custom field(s) to the inherited prepareTaskForSave method.
    $task['data']['inherit_webform_unique_id'] = $form_state->getValue('inherit_webform_unique_id');
  }

  /**
   * {@inheritDoc}
   */
  public function getExecutableForm($modal, MaestroExecuteInteractive $parent) {

    // First, get hold of the interesting previous tasks.
    $templateMachineName = MaestroEngine::getTemplateIdFromProcessId($this->processID);
    $taskMachineName = MaestroEngine::getTaskIdFromQueueId($this->queueID);
    $task = MaestroEngine::getTemplateTaskByID($templateMachineName, $taskMachineName);

    // Get user input from 'inherit_webform_unique_id'
    $webformInheritID = $task['data']['inherit_webform_unique_id'];
    
    // Load its corresponding webform submission.
    $sid = MaestroEngine::getEntityIdentiferByUniqueID($this->processID, $webformInheritID);
    if ($sid) {
      $webform_submission = WebformSubmission::load($sid);
    }
    if (!isset($webform_submission)) {
      \Drupal::logger('os2forms_forloeb')->error(
        "Predecessors must have submissions with webforms attached."
      );
      return FALSE;
    }
    // Copy the fields of the webform submission to the values array.
    foreach ($webform_submission->getData() as $key => $value) {
      if ($value) {
        $field_values[$webformInheritID . '_' . $key] = $value;
      }
    }
    // Now create webform submission, submit and attach to current process.
    $templateTask = MaestroEngine::getTemplateTaskByQueueID($this->queueID);
    $taskUniqueSubmissionId = $templateTask['data']['unique_id'];
    $webformMachineName = $templateTask['data']['webform_machine_name'];

    $values = [];
    $values['webform_id'] = $webformMachineName;
    $values['data'] = $field_values;

    // Create submission.
    $new_submission = WebformSubmission::create($values);

    $errors = WebformSubmissionForm::validateWebformSubmission($webform_submission);

    if (!empty($errors)) {
      \Drupal::logger('os2forms_forloeb')->error(
        "Can't create new submission: " . json_encode($errors)
      );
      return FALSE;
    }
    // Submit it.
    $new_submission = WebformSubmissionForm::submitWebformSubmission($new_submission);

    // Attach it to the Maestro process.
    $sid = $new_submission->id();
    MaestroEngine::createEntityIdentifier(
      $this->processID, $new_submission->getEntityTypeId(),
      $new_submission->bundle(), $taskUniqueSubmissionId, $sid
    );

    return parent::getExecutableForm($modal, $parent);
  }
}