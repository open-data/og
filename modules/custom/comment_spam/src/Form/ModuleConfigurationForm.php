<?php

namespace Drupal\comment_spam\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;


/**
 * Defines a form that configures forms module settings.
 */
class ModuleConfigurationForm extends ConfigFormBase {

    /**
     * {@inheritdoc}
     */
    public function getFormId() {
        return 'comment_spam_admin_settings';
    }

    /**
     * {@inheritdoc}
     */
    protected function getEditableConfigNames() {
        return [
            'comment_spam.settings',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function buildForm(array $form, FormStateInterface $form_state) {
        $form = parent::buildForm($form, $form_state);
        //Get settings for module
        $config = $this->config('comment_spam.settings');

        //Get list of all webforms
        $entities = \Drupal::entityTypeManager()->getStorage('webform')->loadMultiple(NULL);
        $webforms = array();
        foreach( $entities as $entity_id => $entity ) {
            $webforms[$entity_id] = $entity_id;
        }

        //Display current webforms with Spam Filter
        $def_web = $config->get('webform_list');
        $webform_list = array_diff($def_web,array('0'));
        $form['webforms00']['#markup'] = t('<p>Webforms included: '.join(", ",$webform_list).'</p>');

        //List to select webforms to add spam filter
        $form['webforms01']['#markup'] = t('<details><summary>Add webforms</summary>');
        $form['entities']=array(
            '#type'=>'checkboxes',
            '#options' => $webforms,
            '#default_value' => $def_web,
        );
        $form['webforms02']['#markup'] = t('</details>');

        //Get default list and full current list of words to filter by
        $defaultWords = $config->get('default_list');
        $myWords = $config->get('custom_list');
        //Whether or not the default list is included
        $include_default = $config->get('include_default_list');

        //Obtain and Display default list
        $default_rows = "";
        foreach($defaultWords as $badWord){
            $default_rows.=$badWord."<br>";
        }

        $form['default_list'] = array(
            '#type' => 'radios',
            '#title' => $this
                ->t('Include default list?'),
            '#default_value' => $include_default,
            '#options' => array(
                0 => $this
                    ->t('Don\'t include'),
                1 => $this
                    ->t('Include'),
            ),
        );

        $form['text_details']['#markup'] = t('<details><summary>Click here to view the default list</summary>'.$default_rows.'</details>');
        $form['text_list_title']['#markup'] = t('<h3>Current list of banned words/phrases <br> (select checkbox beside a word to remove it)</h3>');

        //Obtain and Display List of current list of banned words
        $current_rows[]=array();

        $count=1;
        foreach($myWords as $badWord){
            $options[$badWord]= ['words' => $badWord,];
            $count++;
            //$current_rows[] = array($badWord);
        }
        $header = [
            'words' => $this
                ->t('Bad word'),
        ];
        //Display in tableselect to allow selection of words for removal
        $form['table'] = array(
            '#type' => 'tableselect',
            '#header' => $header,
            '#options' => $options,
            '#empty' => $this
                ->t('You have not added any words yet'),
        );
        //Remove Selected words
        $form['update_list'] = array(
            '#name' => 'update_list',
            '#type' => 'submit',
            '#value' => t('Remove Selected Words'),
            '#submit' => array([$this, 'updateList']),
        );

/*        $form['block_pattern'] = array(
            '#type' => 'radios',
            '#title' => $this
                ->t('Block individual words or any sequence of letters that match a bad word?'),
            '#default_value' => 0,
            '#options' => array(
                0 => $this
                    ->t('Only block words (recommended)'),
                1 => $this
                    ->t('Block Sequence'),
            ),
        );

*/
        $style="border: red solid; border-radius: 5px; padding:10px;";
        $form['text_warning']['#markup'] =
                t('<br><br><div style="'.$style.'"><h3 style="color: red">WARNING!</h3><br> Blocking sequence may produce unexpected results!<br>
           For example, banning \'ass\' will also block comments with the word \'classic\'. Be careful with what you ban!</div><br> ');
            $form['text_info']['#markup'] = t('<p>Not case sensitive</p>');

            //Text area to add custom words to ban list
            $form['addWords'] = [
            '#type' => 'textarea',
            '#title' => $this->t('Add a word or words separated by line'),
            ];

        $form['actions']['submit']['#value'] = [
            '#type' => 'submit',
            '#value' => $this->t('words to list'),
        ];

        return $form;
    }

    /**
     * @param array $form
     * @param FormStateInterface $form_state
     * Remove words selected in tableselect from config 'custom_list'
     */
    public function updateList(array &$form, FormStateInterface &$form_state) {
        $config = $this->config('comment_spam.settings');
        $result=$config->get('custom_list');
        $wordsToRemove = array_filter($form_state->getValue('table'));


        if(!empty($wordsToRemove)){
            foreach ($wordsToRemove as $del_word){
                if (($key = array_search($del_word, $result)) !== false) {
                    unset($result[$key]);
                }
            }
        }
        $config->set('custom_list',$result)->save();
        $form_state->setRebuild();
    }

    /**
     * {@inheritdoc}
     */
    public function validateForm(array &$form, FormStateInterface $form_state) {
        parent::validateForm($form, $form_state);
    }

    /**
     * {@inheritdoc}
     */
    public function submitForm(array &$form, FormStateInterface $form_state){
        //Obtain values submitted in form
        $wordsToAdd = $form_state->getValue('addWords');
        $default_list_include=$form_state->getValue('default_list');
        $wordsToRemove = array_filter($form_state->getValue('table'));
        //Webforms selected in form
        $webform_add = $form_state->getValue('entities');

        //Obtain values in config before submitting form
        $config = $this->config('comment_spam.settings');
        $defaultWords = $config->get('default_list');
        $result=$config->get('custom_list');

        //If the custom_list from config is not empty, merge old with new
        if(!empty($result)){
            //Include default list
            if($default_list_include==1){
                $result=array_merge($result,$defaultWords);
            }else{
                $result=array_diff($result,$defaultWords);
            }
            //Add words from textarea
            if(!empty($wordsToAdd)) {
                $wordsToAdd = explode("\n", $wordsToAdd);
                $result = array_merge($result, $wordsToAdd);
            }
        }
        //Else if empty, no need to merge
        else{
            if($default_list_include==1){
                $result=$defaultWords;
            }

            if(!empty($wordsToAdd)){
                $wordsToAdd =explode("\n", $wordsToAdd);
                $result=$wordsToAdd;
            }
        }

        if(!empty($wordsToRemove)){
            foreach ($wordsToRemove as $del_word){
                if (($key = array_search($del_word, $result)) !== false) {
                    unset($result[$key]);
                }
            }
        }

        //save results in config
        $config->set('custom_list',$result)->save();
        $config->set('include_default_list',$default_list_include)->save();

        //Get list of current webforms
        $current_webforms = $config->get('webform_list');
        //Webforms that are selected to be added to list
        $new_webforms = array_diff($webform_add,$current_webforms);
        //Webforms that are deselected to be removed from list
        $remove_webforms = array_diff($current_webforms,$webform_add);
        //Save to config
        $config->set('webform_list',$webform_add)->save();

        //The spam_flag field to be added to webforms
        $hidden_field = "\nspam_flag:\n  '#type': checkbox\n  '#title': spam_flag\n  '#disabled': true\n  '#wrapper_attributes':\n    class:\n      - hidden";
        //For each webform to be added
        if($new_webforms!=0){
            foreach($new_webforms as $webform){
                $wf_conf = \Drupal::configFactory()->getEditable('webform.webform.'.$webform);
                //Add spam_flag field to element
                $elements = $wf_conf->get('elements');
                $elements.=$hidden_field;
                $wf_conf->set('elements',$elements);
                //If webform has email_notification handler, add condition to not send email if spam
                $check_handler = $wf_conf->get('handlers.email_notification');
                if($check_handler!=null){
                    $wf_conf->set('handlers.email_notification.conditions.disabled.:input[name="spam_flag"].checked',true);
                }
                $wf_conf->save();
            }
        }
        //For each webform to be removed
        if($remove_webforms!=0){
            foreach($remove_webforms as $webform){
                $wf_conf = \Drupal::configFactory()->getEditable('webform.webform.'.$webform);
                //Remove spam_flag field from elements
                $elements = $wf_conf->get('elements');
                $elements=str_replace($hidden_field,"",$elements);
                $wf_conf->set('elements',$elements);
                //Remove email_notification handler
                $check_handler = $wf_conf->get('handlers.email_notification');
                if($check_handler!=null){
                    $wf_conf->set('handlers.email_notification.conditions',[]);
                }
                $wf_conf->save();
            }
        }
    }

}